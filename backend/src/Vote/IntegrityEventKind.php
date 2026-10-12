<?php

declare(strict_types=1);

namespace App\Vote;

/**
 * Tipul evenimentului din jurnalul de integritate (ADR-0007 §7).
 * Jurnalul nu deosebește votul nou de schimbare sau reconfirmare: diferența ar spune ceva despre istoricul persoanei.
 */
enum IntegrityEventKind: string
{
    case VOTE_SET = 'VOTE_SET';
    case VOTE_WITHDRAWN = 'VOTE_WITHDRAWN';
}
