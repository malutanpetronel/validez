<?php

// CLI helper for smoke tests: solve a public challenge read from stdin.
require dirname(__DIR__).'/vendor/autoload.php';

use AltchaOrg\Altcha\Algorithm\Pbkdf2;
use AltchaOrg\Altcha\Altcha;
use AltchaOrg\Altcha\Challenge;
use AltchaOrg\Altcha\Payload;
use AltchaOrg\Altcha\SolveChallengeOptions;

$challenge = Challenge::fromArray(json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR));
$solution = (new Altcha())->solveChallenge(new SolveChallengeOptions(challenge: $challenge, algorithm: new Pbkdf2(), timeout: 120));
if (!$solution) { fwrite(STDERR, "ALTCHA nu a fost rezolvat în intervalul disponibil.\n"); exit(1); }
echo (new Payload($challenge, $solution))->toBase64();
