<?php

declare(strict_types=1);

namespace App\Ai;

use App\Entity\AiRequest;
use App\Entity\AiSettings;
use App\Entity\TreeNode;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Ulid;

/**
 * ADR-0014 p.7–12: sugestia unui subiect de tip ISSUE dintr-o fotografie. Nu salvează nimic în subiect; scrie doar
 * jurnalul ai_request (prin DBAL). Cache pe hash 24 h: aceeași imagine + prompt + model nu se trimite de două ori și
 * nu consumă din limite.
 */
final class SubjectSuggestionService
{
    public const MAX_CATEGORIES = 80;
    public const CACHE_HOURS = 24;
    public const KINDS = ['WASTE', 'POTHOLE', 'SIDEWALK', 'POLE', 'LIGHTING', 'SIGNAGE', 'VEGETATION', 'STREET_FURNITURE',
        'DRAINAGE', 'DANGEROUS_BUILDING', 'PARKING', 'OTHER'];
    public const SEVERITIES = ['LOW', 'MEDIUM', 'HIGH'];

    public function __construct(
        private readonly Connection $db,
        private readonly EntityManagerInterface $em,
        private readonly AiAnalyzers $analyzers,
        private readonly AiQuotaChecker $quota,
    ) {
    }

    public function settings(): AiSettings
    {
        return $this->em->find(AiSettings::class, AiSettings::ID) ?? throw new \LogicException('Lipsește rândul ai_settings (migrarea AI.2).');
    }

    /** ADR-0014 p.8: toate proprietățile în `required`, fără proprietăți în plus (OpenAI strict). */
    public static function schema(): array
    {
        $bool = ['type' => 'boolean'];
        $string = ['type' => 'string'];
        $properties = [
            'is_public_problem' => $bool,
            'problem_kind' => ['type' => 'string', 'enum' => self::KINDS],
            'title' => $string,
            'description' => $string,
            'severity' => ['type' => 'string', 'enum' => self::SEVERITIES],
            'immediate_danger' => $bool,
            'location_clues' => ['type' => 'array', 'items' => $string],
            'suggested_category' => $string,
            'confidence' => ['type' => 'number'],
            'people_or_plates_visible' => $bool,
        ];

        return ['type' => 'object', 'properties' => $properties, 'required' => array_keys($properties), 'additionalProperties' => false];
    }

    /**
     * @param string $image varianta reprocesată, fără metadate (ImageProcessor::process()->display)
     * @return array{suggestion: array<string, mixed>, provider: string, model: string, cached: bool, costUsd: ?string}
     * @throws AiRefusedException|AiException
     */
    public function suggest(User $admin, TreeNode $node, string $image, string $mime, ?\DateTimeImmutable $takenAt): array
    {
        $settings = $this->settings();
        $provider = $settings->getProvider();
        $model = $settings->activeModel();
        $analyzer = $this->analyzers->get($provider);
        if (!$settings->isEnabled()) {
            throw AiRefusedException::unavailable('Sugestiile AI sunt oprite din setări.');
        }
        if ($analyzer === null || !$analyzer->configured()) {
            throw AiRefusedException::unavailable(sprintf('Cheia API pentru %s nu este configurată pe server.', $provider));
        }

        $categories = $this->categories($node);
        $prompt = $this->prompt($settings, $node, $categories, $takenAt);
        $hash = AiRequest::hash($provider, $model, $prompt, $image);

        $this->db->executeStatement("UPDATE ai_request SET response = NULL WHERE response IS NOT NULL AND created_at < NOW() - INTERVAL '".self::CACHE_HOURS." hours'");
        $cached = $this->db->fetchAssociative('SELECT response, cost_usd FROM ai_request WHERE request_hash = :h AND status = :s AND response IS NOT NULL ORDER BY created_at DESC LIMIT 1',
            ['h' => $hash, 's' => AiRequest::SUCCESS]);
        if ($cached) {
            return ['suggestion' => json_decode((string) $cached['response'], true), 'provider' => $provider, 'model' => $model, 'cached' => true, 'costUsd' => null];
        }

        $now = new \DateTimeImmutable();
        $this->quota->assertAllowed($settings, $admin, $now);

        $id = new Ulid();
        $this->db->insert('ai_request', [
            'id' => $id->toRfc4122(), 'requester_id' => $admin->getId()->toRfc4122(), 'provider' => $provider, 'model' => $model,
            'purpose' => AiRequest::PURPOSE_SUBJECT, 'status' => AiRequest::PENDING, 'request_hash' => $hash, 'created_at' => $now->format('c'),
        ]);
        try {
            $result = $analyzer->analyze($image, $mime, $prompt, self::schema(), $model);
            $suggestion = $this->normalize($result->data, $categories);
        } catch (AiException $e) {
            $this->db->update('ai_request', ['status' => AiRequest::ERROR, 'error' => mb_substr($e->getMessage(), 0, 500), 'finished_at' => (new \DateTimeImmutable())->format('c')],
                ['id' => $id->toRfc4122()]);
            throw $e;
        }
        $cost = self::cost($settings, $model, $result->inputTokens, $result->outputTokens);
        $this->db->update('ai_request', [
            'status' => AiRequest::SUCCESS, 'model' => $result->model, 'input_tokens' => $result->inputTokens, 'output_tokens' => $result->outputTokens,
            'cost_usd' => $cost, 'duration_ms' => $result->durationMs, 'response' => json_encode($suggestion, \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR),
            'finished_at' => (new \DateTimeImmutable())->format('c'),
        ], ['id' => $id->toRfc4122()]);

        return ['suggestion' => $suggestion, 'provider' => $provider, 'model' => $result->model, 'cached' => false, 'costUsd' => $cost];
    }

    /** USD estimat din tokenii raportați și prețul modelului (6 zecimale); null = model fără preț. */
    public static function cost(AiSettings $settings, string $model, int $in, int $out): ?string
    {
        $price = $settings->modelPrice($model);

        return $price === null ? null : number_format(($in * $price['input'] + $out * $price['output']) / 1_000_000, 6, '.', '');
    }

    /**
     * Nodul și subarborele lui (cel mult MAX_CATEGORIES), cu coduri scurte c1…cN pentru prompt.
     * @return array<string, array{id: string, path: string}>
     */
    public function categories(TreeNode $node): array
    {
        $nodeId = $node->getId()->toRfc4122();
        $prefix = $this->db->fetchFirstColumn('SELECT a.name FROM tree_node a, tree_node n WHERE n.id = :id AND a.path @> n.path AND a.id <> n.id ORDER BY nlevel(a.path)', ['id' => $nodeId]);
        $rows = $this->db->fetchAllAssociative('SELECT t.id, t.parent_id, t.name FROM tree_node t, tree_node n WHERE n.id = :id AND t.path <@ n.path ORDER BY t.path LIMIT '.self::MAX_CATEGORIES,
            ['id' => $nodeId]);
        $paths = [];
        $out = [];
        foreach ($rows as $i => $row) {
            $parentPath = $row['id'] === $nodeId ? $prefix : ($paths[$row['parent_id']] ?? $prefix);
            $paths[$row['id']] = [...$parentPath, $row['name']];
            $out['c'.($i + 1)] = ['id' => Ulid::fromString($row['id'])->toBase32(), 'path' => implode(' > ', $paths[$row['id']])];
        }

        return $out;
    }

    /** @param array<string, array{id: string, path: string}> $categories */
    private function prompt(AiSettings $settings, TreeNode $node, array $categories, ?\DateTimeImmutable $takenAt): string
    {
        $list = implode("\n", array_map(static fn (string $code, array $c): string => "$code: {$c['path']}", array_keys($categories), $categories));

        return strtr($settings->getPrompt(), [
            '{limba}' => $settings->getLanguage(),
            '{categorie}' => $categories['c1']['path'] ?? $node->getName(),
            '{categorii}' => $list,
            '{data}' => $takenAt?->setTimezone(new \DateTimeZone('Europe/Bucharest'))->format('d.m.Y H:i') ?? 'necunoscută',
        ]);
    }

    /**
     * ADR-0014 p.8: un răspuns invalid e eroare și nu ajunge în formular; valorile în afara listelor se corectează.
     * @param array<string, mixed> $d
     * @param array<string, array{id: string, path: string}> $categories
     * @return array<string, mixed>
     */
    private function normalize(array $d, array $categories): array
    {
        if (!\is_bool($d['is_public_problem'] ?? null) || !\is_string($d['title'] ?? null) || !\is_string($d['description'] ?? null)) {
            throw AiException::short('Răspunsul AI nu are câmpurile obligatorii.');
        }
        $text = static fn (mixed $v, int $max): string => \is_string($v) ? mb_substr(trim(preg_replace('/\s+/u', ' ', $v) ?? ''), 0, $max) : '';
        $clues = array_values(array_filter(array_map(static fn (mixed $c): string => $text($c, 200), \is_array($d['location_clues'] ?? null) ? \array_slice($d['location_clues'], 0, 10) : [])));
        $category = $categories[\is_string($d['suggested_category'] ?? null) ? trim($d['suggested_category']) : ''] ?? null;
        $confidence = is_numeric($d['confidence'] ?? null) ? max(0.0, min(1.0, (float) $d['confidence'])) : 0.0;
        $description = \is_string($d['description']) ? mb_substr(trim($d['description']), 0, 4000) : '';

        return [
            'type' => 'ISSUE',
            'isPublicProblem' => $d['is_public_problem'],
            'problemKind' => \in_array($d['problem_kind'] ?? null, self::KINDS, true) ? $d['problem_kind'] : 'OTHER',
            'title' => $text($d['title'], 90),
            'description' => $description,
            'severity' => \in_array($d['severity'] ?? null, self::SEVERITIES, true) ? $d['severity'] : 'MEDIUM',
            'immediateDanger' => ($d['immediate_danger'] ?? false) === true,
            'locationClues' => $clues,
            'categoryId' => $category['id'] ?? null,
            'categoryPath' => $category['path'] ?? null,
            'confidence' => round($confidence, 2),
            'peopleOrPlatesVisible' => ($d['people_or_plates_visible'] ?? false) === true,
        ];
    }
}
