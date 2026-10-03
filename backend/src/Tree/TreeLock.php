<?php

declare(strict_types=1);

namespace App\Tree;

use Doctrine\DBAL\Connection;

/**
 * Serializeaza modificarile de structura ale arborelui (creare, mutare, ordonare).
 *
 * pg_advisory_xact_lock se elibereaza automat la commit/rollback, deci trebuie apelat
 * in interiorul tranzactiei. Un singur lock global: modificarile de structura sunt rare
 * (administratori) si asa nu exista ordini de blocare diferite intre mutari concurente
 * (protectia la cicluri sub concurenta, plan Step 1.3).
 */
final class TreeLock
{
    /** Cheie fixa pentru arbore (orice int64; aleasa sa nu se ciocneasca cu alte lock-uri ale aplicatiei). */
    private const KEY = 7_302_001;

    public function __construct(private readonly Connection $connection)
    {
    }

    public function acquire(): void
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('TreeLock::acquire() trebuie apelat in interiorul unei tranzactii.');
        }

        $this->connection->executeQuery('SELECT pg_advisory_xact_lock(:key)', ['key' => self::KEY]);
    }
}
