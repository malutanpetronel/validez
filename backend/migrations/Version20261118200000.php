<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** L.2b: versiunea Termenilor acceptată la înregistrare și momentul acceptării. Conturile existente rămân NULL. */
final class Version20261118200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'L.2b: app_user.terms_accepted_version/terms_accepted_at';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user ADD terms_accepted_version VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE app_user ADD terms_accepted_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user DROP terms_accepted_version');
        $this->addSql('ALTER TABLE app_user DROP terms_accepted_at');
    }
}
