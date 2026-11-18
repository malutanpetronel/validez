<?php

declare(strict_types=1);

namespace App\Entity;

use App\Ai\ImageAnalyzer;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * ADR-0014 p.5: setările AI ale platformei — un singur rând (id 1, creat de migrare), citit și editat doar de admini
 * (AiSettingsController). Cheile API NU sunt aici (doar în env). Prețul per model servește plafoanelor în USD: un
 * model fără preț nu poate fi folosit (AiQuotaChecker refuză), ca un model scump să nu treacă necontorizat.
 */
#[ORM\Entity]
#[ORM\Table(name: 'ai_settings')]
class AiSettings
{
    public const ID = 1;

    /** ADR-0014 p.4: modelele sunt fixe deocamdată — se schimbă doar din cod, cu o migrare care actualizează rândul. */
    public const MODEL_ANTHROPIC = 'claude-haiku-5-5';
    public const MODEL_OPENAI = 'gpt-6-luna';

    /** USD per 1M tokeni (oct. 2026); editabile din panou. */
    public const DEFAULT_PRICES = [
        self::MODEL_ANTHROPIC => ['input' => 0.10, 'output' => 0.50],
        self::MODEL_OPENAI => ['input' => 0.10, 'output' => 0.50],
    ];

    /** ADR-0014 p.7. Placeholdere: {limba}, {categorie}, {categorii}, {data}. */
    public const DEFAULT_PROMPT = <<<'TXT'
Ești un funcționar atent care redactează sesizări civice pentru o primărie din România. Privește fotografia și stabilește dacă arată o problemă din spațiul public (de exemplu: gunoi sau deșeuri abandonate, groapă în carosabil, trotuar degradat sau nefinisat, stâlp înclinat sau deteriorat, iluminat stricat, semnalizare lipsă sau deteriorată, vegetație necontrolată, mobilier urban stricat, scurgere sau capac de canal lipsă, construcție periculoasă, parcare care blochează trecerea).
Scrie în limba {limba}, sobru și factual, la persoana a treia:
- un titlu scurt (maximum 90 de caractere) care numește problema și, dacă se vede, reperul;
- o descriere de 2–4 propoziții: ce se vede, de ce e o problemă (siguranță, accesibilitate, igienă) și ce intervenție ar fi potrivită. Nu exagera și nu inventa detalii care nu se văd.
Indicii de localizare: transcrie exact textul vizibil al plăcuțelor de stradă, numerelor de casă, firmelor sau al reperelor cunoscute. Nu ghici adresa.
Nu identifica persoanele și nu transcrie numerele de înmatriculare; semnalează doar dacă apar în imagine.
Alege categoria cea mai potrivită doar din lista dată (codul dinaintea căii); dacă nu se potrivește niciuna, lasă câmpul gol.
Categoria curentă: {categorie}.
Categorii disponibile:
{categorii}
Data fotografiei: {data}.
Dacă fotografia nu arată o problemă din spațiul public, marchează asta și lasă titlul și descrierea goale.
TXT;

    #[ORM\Id]
    #[ORM\Column(type: Types::INTEGER)]
    private int $id = self::ID;

    /** Comutatorul global: oprit = nicio cerere AI. Pornit doar după ce cheile sunt în env. */
    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $enabled = false;

    #[ORM\Column(length: 20)]
    private string $provider = ImageAnalyzer::ANTHROPIC;

    #[ORM\Column(length: 100)]
    private string $modelAnthropic = self::MODEL_ANTHROPIC;

    #[ORM\Column(length: 100)]
    private string $modelOpenai = self::MODEL_OPENAI;

    /** null / gol = promptul implicit (rândul nu trebuie migrat când se schimbă textul implicit). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $prompt = null;

    #[ORM\Column(length: 10)]
    private string $language = 'română';

    /** @var array<string, array{input: float|int, output: float|int}> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $modelPrices = self::DEFAULT_PRICES;

    #[ORM\Column(type: Types::INTEGER)]
    private int $dailyLimitPerAdmin = 30;

    #[ORM\Column(type: Types::INTEGER)]
    private int $dailyRequestsCap = 30;

    /** Doctrine întoarce decimal ca string. */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private string $dailyUsdCap = '1.00';

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private string $monthlyUsdCap = '10.00';

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function activeModel(): string
    {
        return $this->provider === ImageAnalyzer::OPENAI ? $this->modelOpenai : $this->modelAnthropic;
    }

    /** @return array{input: float, output: float}|null */
    public function modelPrice(string $model): ?array
    {
        $price = $this->modelPrices[$model] ?? null;

        return \is_array($price) && is_numeric($price['input'] ?? null) && is_numeric($price['output'] ?? null)
            ? ['input' => (float) $price['input'], 'output' => (float) $price['output']] : null;
    }

    public function isEnabled(): bool { return $this->enabled; }
    public function setEnabled(bool $enabled): void { $this->enabled = $enabled; }
    public function getProvider(): string { return $this->provider; }
    public function setProvider(string $provider): void { $this->provider = $provider; }
    public function getModelAnthropic(): string { return $this->modelAnthropic; }
    public function getModelOpenai(): string { return $this->modelOpenai; }
    public function getPrompt(): string { return trim((string) $this->prompt) !== '' ? (string) $this->prompt : self::DEFAULT_PROMPT; }
    public function isDefaultPrompt(): bool { return trim((string) $this->prompt) === ''; }
    public function setPrompt(?string $prompt): void { $this->prompt = $prompt !== null && trim($prompt) !== '' && trim($prompt) !== trim(self::DEFAULT_PROMPT) ? $prompt : null; }
    public function getLanguage(): string { return $this->language; }
    public function setLanguage(string $language): void { $this->language = trim($language); }
    /** @return array<string, array{input: float|int, output: float|int}> */
    public function getModelPrices(): array { return $this->modelPrices; }
    /** @param array<string, array{input: float|int, output: float|int}> $prices */
    public function setModelPrices(array $prices): void { $this->modelPrices = $prices; }
    public function getDailyLimitPerAdmin(): int { return $this->dailyLimitPerAdmin; }
    public function setDailyLimitPerAdmin(int $v): void { $this->dailyLimitPerAdmin = $v; }
    public function getDailyRequestsCap(): int { return $this->dailyRequestsCap; }
    public function setDailyRequestsCap(int $v): void { $this->dailyRequestsCap = $v; }
    public function getDailyUsdCap(): string { return $this->dailyUsdCap; }
    public function setDailyUsdCap(string $v): void { $this->dailyUsdCap = $v; }
    public function getMonthlyUsdCap(): string { return $this->monthlyUsdCap; }
    public function setMonthlyUsdCap(string $v): void { $this->monthlyUsdCap = $v; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function touch(): void { $this->updatedAt = new \DateTimeImmutable(); }
}
