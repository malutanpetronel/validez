<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\Connection;

/**
 * Curățarea bazei de test. TRUNCATE ... CASCADE golește și tabelele care referă subiectele/utilizatorii
 * (revizii, voturi, istoric, jurnal de integritate), fără să declanșeze triggerul append-only (care e pe rând,
 * pentru UPDATE/DELETE). `vote_type` (seed) nu referă nimic, deci rămâne.
 * Geografia (ADR-0012) se golește mereu: un set de limite activ lăsat de alt test ar încadra pinii (G.2).
 */
final class Database
{
    public static function reset(Connection $db, string ...$extraTables): void
    {
        $tables = array_values(array_unique(array_merge(['civic_subject', 'tree_node', 'refresh_token', 'app_user', 'slug_redirect',
            'administrative_boundary', 'boundary_set', 'administrative_unit'], $extraTables)));
        $db->executeStatement('TRUNCATE '.implode(', ', $tables).' CASCADE');
    }
}
