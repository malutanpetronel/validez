<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ADR-0012 p.3 (G.1a): PostGIS nu e extensie "trusted", deci migratorul (validez_owner) nu o poate crea.
 * O creeaza superuserul inaintea migrarilor: initdb pe volume noi, docker/postgres/admin/extensions.sql
 * pe bazele existente (bin/deploy-db.sh pe productie). Migrarea doar verifica prezenta ei.
 */
final class Version20261016190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'G.1a: verifica extensia PostGIS, creata administrativ (ADR-0012 p.3)';
    }

    public function preUp(Schema $schema): void
    {
        $this->abortIf(
            false === $this->connection->fetchOne("SELECT 1 FROM pg_extension WHERE extname = 'postgis'"),
            'Extensia postgis lipseste pe baza '.$this->connection->getDatabase().'. Ruleaza ca superuser '
            .'docker/postgres/admin/extensions.sql (pe productie o face bin/deploy-db.sh), apoi reia migrarile.'
        );
    }

    public function up(Schema $schema): void
    {
        // Confirma ca functiile PostGIS sunt utilizabile de rolul care ruleaza migrarile.
        $this->addSql('SELECT postgis_lib_version()');
    }

    public function down(Schema $schema): void
    {
        // Extensia nu se sterge prin migrare: a fost creata administrativ si poate avea dependente.
        $this->addSql('SELECT 1');
    }
}
