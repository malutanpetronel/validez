<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261007210000 extends AbstractMigration
{
    public function getDescription(): string { return 'Public registration, hashed email codes and atomic single-use ALTCHA'; }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user ADD email_verified BOOLEAN DEFAULT TRUE NOT NULL, ADD confirmation_code_hash VARCHAR(64) DEFAULT NULL, ADD code_requested_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, ADD code_attempts INT DEFAULT 0 NOT NULL');
        $this->addSql('CREATE TABLE altcha_used_challenge (id VARCHAR(64) NOT NULL PRIMARY KEY, expires_at TIMESTAMP(0) WITH TIME ZONE NOT NULL)');
        $this->addSql('CREATE INDEX idx_altcha_expiry ON altcha_used_challenge (expires_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE altcha_used_challenge');
        $this->addSql('ALTER TABLE app_user DROP email_verified, DROP confirmation_code_hash, DROP code_requested_at, DROP code_attempts');
    }
}
