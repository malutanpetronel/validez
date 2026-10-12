<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\Connection;

/**
 * Curățarea bazei de test. TRUNCATE ... CASCADE golește și tabelele care referă subiectele/utilizatorii
 * (revizii, voturi, istoric, jurnal de integritate), fără să declanșeze triggerul append-only (care e pe rând,
 * pentru UPDATE/DELETE). `vote_type` (seed) nu referă nimic, deci rămâne.
 */
final class Database
{
    public static function reset(Connection $db, string ...$extraTables): void
    {
        $tables = array_merge(['civic_subject', 'tree_node', 'refresh_token', 'app_user'], $extraTables);
        $db->executeStatement('TRUNCATE '.implode(', ', $tables).' CASCADE');
    }
}
