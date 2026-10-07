<?php

declare(strict_types=1);

namespace App\Auth;

use AltchaOrg\Altcha\Algorithm\Pbkdf2;
use AltchaOrg\Altcha\Altcha;
use AltchaOrg\Altcha\CreateChallengeOptions;
use AltchaOrg\Altcha\Payload;
use AltchaOrg\Altcha\VerifySolutionOptions;
use Doctrine\DBAL\Connection;

/** Adapted from sf4: validate cryptographically, then consume atomically in shared storage. */
final class AltchaService
{
    private readonly Altcha $altcha;

    public function __construct(string $secret, private readonly int $cost, private readonly Connection $db)
    {
        $this->altcha = new Altcha(
            hmacSignatureSecret: hash_hmac('sha256', 'validez-altcha-challenge', $secret),
            hmacKeySignatureSecret: hash_hmac('sha256', 'validez-altcha-key', $secret),
        );
    }

    public function createChallenge(): array
    {
        return $this->altcha->createChallenge(new CreateChallengeOptions(
            algorithm: new Pbkdf2(), cost: max(1, min($this->cost, 10000)),
            counter: random_int(5000, 10000), expiresAt: time() + 600,
        ))->toArray();
    }

    public function verify(mixed $encoded): bool
    {
        if (!is_string($encoded) || $encoded === '' || strlen($encoded) > 10000) return false;
        try {
            $payload = Payload::fromBase64($encoded);
            if (!$this->altcha->verifySolution(new VerifySolutionOptions(payload: $payload, algorithm: new Pbkdf2()))->verified) return false;
            $expiresAt = $payload->challenge->parameters->expiresAt;
            if ($expiresAt === null || $expiresAt < time() || $expiresAt > time() + 600) return false;
        } catch (\Throwable) {
            return false;
        }
        $this->db->executeStatement('DELETE FROM altcha_used_challenge WHERE expires_at < NOW()');
        return $this->db->executeStatement('INSERT INTO altcha_used_challenge (id, expires_at) VALUES (?, ?) ON CONFLICT DO NOTHING', [
            hash('sha256', $payload->challenge->signature ?? ''), gmdate('Y-m-d H:i:sP', $expiresAt),
        ]) === 1;
    }
}
