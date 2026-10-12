<?php

declare(strict_types=1);

namespace App\Vote;

/** Acțiunea înregistrată în istoricul privat al votului (ADR-0007 §7). */
enum VoteHistoryAction: string
{
    case VOTE = 'VOTE';
    case CHANGE = 'CHANGE';
    case RECONFIRM = 'RECONFIRM';
    case WITHDRAW = 'WITHDRAW';
}
