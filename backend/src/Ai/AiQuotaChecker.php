<?php

declare(strict_types=1);

namespace App\Ai;

use App\Entity\AiRequest;
use App\Entity\AiSettings;
use App\Entity\User;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * ADR-0014 p.5: se poate face o cerere AI? Ordinea: comutatorul global și prețul modelului activ; plafoanele globale
 * (cereri și USD pe zi, USD pe lună); limita pe zi a adminului. Ziua și luna se socotesc în ora României. Două cereri
 * simultane pot depăși cu una limita — acceptat (budget limit din consola furnizorului rămâne bariera finală).
 */
final class AiQuotaChecker
{
    private const TZ = 'Europe/Bucharest';
    private const COUNTED = [AiRequest::PENDING, AiRequest::SUCCESS];

    public function __construct(private readonly Connection $db)
    {
    }

    /** @throws AiRefusedException */
    public function assertAllowed(AiSettings $settings, User $admin, \DateTimeImmutable $now): void
    {
        if (!$settings->isEnabled()) {
            throw AiRefusedException::unavailable('Sugestiile AI sunt oprite din setări.');
        }
        $model = $settings->activeModel();
        if ($settings->modelPrice($model) === null) {
            throw AiRefusedException::unavailable(sprintf('Modelul „%s” nu are preț configurat.', $model));
        }
        $u = $this->usage($admin, $now);
        if ($u['todayRequests'] >= $settings->getDailyRequestsCap() || $u['todayUsd'] >= (float) $settings->getDailyUsdCap()) {
            throw AiRefusedException::limit('Limita zilnică a platformei a fost atinsă. Revino mâine.');
        }
        if ($u['monthUsd'] >= (float) $settings->getMonthlyUsdCap()) {
            throw AiRefusedException::limit('Limita lunară a platformei a fost atinsă.');
        }
        if ($u['mineToday'] >= $settings->getDailyLimitPerAdmin()) {
            throw AiRefusedException::limit('Ți-ai atins limita zilnică de sugestii AI. Revino mâine.');
        }
    }

    /** @return array{todayRequests: int, todayUsd: float, monthUsd: float, mineToday: int} */
    public function usage(User $admin, \DateTimeImmutable $now): array
    {
        $local = $now->setTimezone(new \DateTimeZone(self::TZ));
        $dayStart = $local->setTime(0, 0);
        $monthStart = $local->modify('first day of this month')->setTime(0, 0);
        $row = $this->db->fetchAssociative(
            'SELECT COUNT(*) FILTER (WHERE created_at >= :day) AS today_requests,
                    COALESCE(SUM(cost_usd) FILTER (WHERE created_at >= :day), 0) AS today_usd,
                    COALESCE(SUM(cost_usd), 0) AS month_usd,
                    COUNT(*) FILTER (WHERE created_at >= :day AND requester_id = :me) AS mine_today
             FROM ai_request WHERE status IN (:s) AND created_at >= :month',
            ['s' => self::COUNTED, 'day' => $dayStart->format('c'), 'month' => $monthStart->format('c'), 'me' => $admin->getId()->toRfc4122()],
            ['s' => ArrayParameterType::STRING],
        ) ?: [];

        return [
            'todayRequests' => (int) ($row['today_requests'] ?? 0),
            'todayUsd' => (float) ($row['today_usd'] ?? 0),
            'monthUsd' => (float) ($row['month_usd'] ?? 0),
            'mineToday' => (int) ($row['mine_today'] ?? 0),
        ];
    }
}
