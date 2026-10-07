<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261007200000 extends AbstractMigration
{
    public function getDescription(): string { return 'Step 2.1: isolated private notes and exact public cost estimates'; }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE civic_subject ADD cost_estimate NUMERIC(12, 2) DEFAULT NULL, ADD cost_currency VARCHAR(3) DEFAULT NULL, ADD cost_estimate_scope TEXT DEFAULT NULL');
        $this->addSql("ALTER TABLE civic_subject ADD CONSTRAINT chk_subject_cost_complete CHECK ((cost_estimate IS NULL AND cost_currency IS NULL AND cost_estimate_scope IS NULL) OR (cost_estimate IS NOT NULL AND cost_currency IS NOT NULL AND cost_estimate_scope IS NOT NULL AND cost_estimate >= 0 AND cost_currency IN ('RON', 'EUR', 'USD', 'GBP') AND cost_estimate_scope ~ '[^[:space:]]' AND char_length(cost_estimate_scope) <= 2000))");
        $this->addSql('CREATE TABLE subject_private_note (subject_id UUID NOT NULL, author_id UUID NOT NULL, text TEXT NOT NULL, PRIMARY KEY (subject_id))');
        $this->addSql('CREATE INDEX idx_private_note_author ON subject_private_note (author_id)');
        $this->addSql('ALTER TABLE subject_private_note ADD CONSTRAINT fk_private_note_subject FOREIGN KEY (subject_id) REFERENCES civic_subject (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE subject_private_note ADD CONSTRAINT fk_private_note_author FOREIGN KEY (author_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE subject_private_note ADD CONSTRAINT chk_private_note_length CHECK (char_length(text) <= 10000)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE subject_private_note');
        $this->addSql('ALTER TABLE civic_subject DROP CONSTRAINT chk_subject_cost_complete');
        $this->addSql('ALTER TABLE civic_subject DROP cost_estimate, DROP cost_currency, DROP cost_estimate_scope');
    }
}
