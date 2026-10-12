<?php

declare(strict_types=1);

namespace App\Vote;

/**
 * Comutatorul votării (Step 4 — frontend, ADR-0007): sursa unică; frontendul citește `votingEnabled` din API.
 * Oprit: votul, schimbarea, reconfirmarea și acordarea consimțământului → 409 `voting_disabled`.
 * Citirea, exportul și retragerea votului/consimțământului rămân disponibile.
 * Implicit oprit; `VOTING_ENABLED=true` doar în dev/test, până la rezolvarea punctelor deschise din ADR-0007.
 */
final class VotingSwitch
{
    public function __construct(private readonly bool $enabled)
    {
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}
