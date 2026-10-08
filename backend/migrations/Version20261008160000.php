<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261008160000 extends AbstractMigration
{
    public function getDescription(): string { return 'Password reset email codes and access-token revocation'; }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user ADD password_reset_code_hash VARCHAR(64) DEFAULT NULL, ADD password_reset_requested_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, ADD password_reset_attempts INT DEFAULT 0 NOT NULL, ADD credential_version INT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user DROP password_reset_code_hash, DROP password_reset_requested_at, DROP password_reset_attempts, DROP credential_version');
    }
}
