<?php

declare(strict_types=1);

namespace App\Controller;

use App\Ai\AiAnalyzers;
use App\Ai\AiException;
use App\Ai\AiQuotaChecker;
use App\Ai\AiRefusedException;
use App\Ai\ImageAnalyzer;
use App\Ai\SubjectSuggestionService;
use App\Entity\AiSettings;
use App\Entity\User;
use App\Media\ImageProcessor;
use App\Media\InvalidImageException;
use App\Repository\TreeNodeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Ulid;

/** ADR-0014: setările AI și sugestia de subiect din fotografie — doar pentru admini (p.1). */
#[IsGranted(User::ROLE_ADMIN)]
final class AiController extends AbstractController
{
    public function __construct(
        private readonly SubjectSuggestionService $suggestions,
        private readonly AiAnalyzers $analyzers,
        private readonly AiQuotaChecker $quota,
    ) {
    }

    #[Route('/api/admin/ai-settings', methods: ['GET'])]
    public function settings(): JsonResponse
    {
        return $this->settingsResponse($this->suggestions->settings());
    }

    #[Route('/api/admin/ai-settings', methods: ['PATCH'])]
    public function updateSettings(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $s = $this->suggestions->settings();
        $body = $request->toArray();
        $errors = [];
        if (\array_key_exists('enabled', $body)) {
            \is_bool($body['enabled']) ? $s->setEnabled($body['enabled']) : $errors['enabled'] = 'Valoare da/nu.';
        }
        if (\array_key_exists('provider', $body)) {
            \in_array($body['provider'], [ImageAnalyzer::ANTHROPIC, ImageAnalyzer::OPENAI], true) ? $s->setProvider($body['provider']) : $errors['provider'] = 'Furnizor necunoscut.';
        }
        if (\array_key_exists('prompt', $body)) {
            $ok = $body['prompt'] === null || (\is_string($body['prompt']) && mb_strlen($body['prompt']) <= 8000);
            $ok ? $s->setPrompt($body['prompt']) : $errors['prompt'] = 'Promptul are maximum 8000 de caractere.';
        }
        if (\array_key_exists('language', $body)) {
            \is_string($body['language']) && trim($body['language']) !== '' && mb_strlen($body['language']) <= 10
                ? $s->setLanguage($body['language']) : $errors['language'] = 'Limba: 1–10 caractere.';
        }
        foreach (['dailyLimitPerAdmin' => 'setDailyLimitPerAdmin', 'dailyRequestsCap' => 'setDailyRequestsCap'] as $key => $setter) {
            if (\array_key_exists($key, $body)) {
                \is_int($body[$key]) && $body[$key] >= 0 && $body[$key] <= 10000 ? $s->$setter($body[$key]) : $errors[$key] = 'Număr întreg între 0 și 10000.';
            }
        }
        foreach (['dailyUsdCap' => 'setDailyUsdCap', 'monthlyUsdCap' => 'setMonthlyUsdCap'] as $key => $setter) {
            if (\array_key_exists($key, $body)) {
                is_numeric($body[$key]) && (float) $body[$key] >= 0 && (float) $body[$key] <= 1000
                    ? $s->$setter(number_format((float) $body[$key], 2, '.', '')) : $errors[$key] = 'Sumă în USD între 0 și 1000.';
            }
        }
        if (\array_key_exists('modelPrices', $body)) {
            $prices = $this->validPrices($body['modelPrices']);
            $prices !== null ? $s->setModelPrices($prices) : $errors['modelPrices'] = 'Fiecare model are nevoie de prețuri „input” și „output” (USD / 1M tokeni, ≥ 0).';
        }
        // ADR-0014 p.4: modelele nu se schimbă prin API.
        foreach (['modelAnthropic', 'modelOpenai'] as $key) {
            if (\array_key_exists($key, $body)) $errors[$key] = 'Modelele sunt fixe; se schimbă doar din cod.';
        }
        if ($errors) {
            $em->clear();

            return $this->json(['message' => 'Setările nu au fost salvate.', 'errors' => $errors], 422);
        }
        $s->touch();
        $em->flush();

        return $this->settingsResponse($s);
    }

    #[Route('/api/ai/subject-suggestion', methods: ['POST'])]
    public function subjectSuggestion(Request $request, TreeNodeRepository $nodes, ImageProcessor $processor): JsonResponse
    {
        $user = $this->getUser();
        \assert($user instanceof User);
        $nodeId = $request->request->get('node');
        if (!\is_string($nodeId) || !Ulid::isValid($nodeId) || !($node = $nodes->find(Ulid::fromString($nodeId)))) {
            throw new UnprocessableEntityHttpException('Alege categoria în care creezi subiectul.');
        }
        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            throw new UnprocessableEntityHttpException('Lipsește fotografia (câmpul „file”).');
        }
        if (!$file->isValid()) {
            throw \in_array($file->getError(), [\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE], true)
                ? new HttpException(413, 'Fotografia depășește 10 MB.') : new UnprocessableEntityHttpException('Fotografia nu a putut fi încărcată.');
        }
        $takenAt = null;
        $raw = $request->request->get('takenAt');
        if (\is_string($raw) && $raw !== '') {
            try { $takenAt = new \DateTimeImmutable($raw); } catch (\Exception) { $takenAt = null; }
        }
        try {
            // ADR-0014 p.6: la AI pleacă doar varianta reprocesată (fără metadate), nu fișierul primit.
            $image = $processor->process($file->getPathname());
        } catch (InvalidImageException $e) {
            throw $e->tooLarge ? new HttpException(413, $e->getMessage()) : new UnprocessableEntityHttpException($e->getMessage());
        }
        try {
            $result = $this->suggestions->suggest($user, $node, $image->display, 'image/webp', $takenAt);
        } catch (AiRefusedException $e) {
            return $this->noStore($this->json(['message' => $e->getMessage()], $e->status));
        } catch (AiException $e) {
            return $this->noStore($this->json(['message' => 'Sugestia AI nu a reușit. Încearcă din nou sau completează manual.', 'detail' => $e->getMessage()], 502));
        }

        return $this->noStore($this->json($result));
    }

    private function settingsResponse(AiSettings $s): JsonResponse
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $this->noStore($this->json([
            'enabled' => $s->isEnabled(),
            'provider' => $s->getProvider(),
            'models' => [ImageAnalyzer::ANTHROPIC => $s->getModelAnthropic(), ImageAnalyzer::OPENAI => $s->getModelOpenai()],
            'keys' => array_map(static fn (ImageAnalyzer $a): bool => $a->configured(), $this->analyzers->all()),
            'prompt' => $s->getPrompt(),
            'defaultPrompt' => AiSettings::DEFAULT_PROMPT,
            'isDefaultPrompt' => $s->isDefaultPrompt(),
            'language' => $s->getLanguage(),
            'modelPrices' => $s->getModelPrices(),
            'dailyLimitPerAdmin' => $s->getDailyLimitPerAdmin(),
            'dailyRequestsCap' => $s->getDailyRequestsCap(),
            'dailyUsdCap' => $s->getDailyUsdCap(),
            'monthlyUsdCap' => $s->getMonthlyUsdCap(),
            'usage' => $this->quota->usage($user, new \DateTimeImmutable()),
            'updatedAt' => $s->getUpdatedAt()->format(\DATE_ATOM),
        ]));
    }

    /** @return array<string, array{input: float, output: float}>|null */
    private function validPrices(mixed $prices): ?array
    {
        if (!\is_array($prices) || array_is_list($prices) || \count($prices) > 20) return null;
        $out = [];
        foreach ($prices as $model => $price) {
            if (!\is_string($model) || $model === '' || !\is_array($price) || !is_numeric($price['input'] ?? null) || !is_numeric($price['output'] ?? null)
                || (float) $price['input'] < 0 || (float) $price['output'] < 0) return null;
            $out[$model] = ['input' => (float) $price['input'], 'output' => (float) $price['output']];
        }

        return $out;
    }

    private function noStore(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
