<?php

declare(strict_types=1);

namespace App\Tests\Support;

use AltchaOrg\Altcha\Algorithm\Pbkdf2;
use AltchaOrg\Altcha\Altcha;
use AltchaOrg\Altcha\Challenge;
use AltchaOrg\Altcha\Payload;
use AltchaOrg\Altcha\SolveChallengeOptions;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

trait AltchaPayload
{
    private function solveAltcha(KernelBrowser $client): string
    {
        $client->request('GET', '/api/captcha/challenge', server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $challenge = Challenge::fromArray(json_decode((string) $client->getResponse()->getContent(), true));
        $solution = (new Altcha())->solveChallenge(new SolveChallengeOptions(challenge: $challenge, algorithm: new Pbkdf2(), timeout: 10));
        self::assertNotNull($solution);
        return (new Payload($challenge, $solution))->toBase64();
    }
}
