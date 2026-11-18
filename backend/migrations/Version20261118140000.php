<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** L.5b: „Șterge-mi contul” — linkul de ștergere (hash + momentul cererii) și momentul ștergerii contului. */
final class Version20261118140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'L.5b: app_user.account_deletion_token_hash/account_deletion_requested_at/deleted_at';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user ADD account_deletion_token_hash VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE app_user ADD account_deletion_requested_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE app_user ADD deleted_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user DROP account_deletion_token_hash');
        $this->addSql('ALTER TABLE app_user DROP account_deletion_requested_at');
        $this->addSql('ALTER TABLE app_user DROP deleted_at');
    }
}
