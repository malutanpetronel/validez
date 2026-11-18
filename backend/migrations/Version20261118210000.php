<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * G.3, varianta A (18.11.2026): momentul primei completări a ariei unui subiect vechi (fără revizie nouă).
 * Version20261118191351 (aria + instantaneul) rulase deja pe producție; aici doar coloana nouă.
 */
final class Version20261118210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'G.3 (varianta A): civic_subject.area_completed_at';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE civic_subject ADD area_completed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE civic_subject DROP area_completed_at');
    }
}
