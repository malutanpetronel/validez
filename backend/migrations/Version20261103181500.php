<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * P.1 (ADR-0013): sondaje — variantele pe subiect și în revizie, data de închidere, tipul de vot CHOICE.
 * Fără date de migrat: nu există subiecte POLL înainte de această versiune.
 */
final class Version20261103181500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'P.1: civic_subject.poll_options/poll_closes_at, subject_revision.poll_options, vote_type CHOICE (ADR-0013)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE civic_subject ADD poll_options JSONB DEFAULT NULL');
        $this->addSql('ALTER TABLE civic_subject ADD poll_closes_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE subject_revision ADD poll_options JSONB DEFAULT NULL');
        // Contractul (ADR-0013 p.1–2, p.5): POLL ⇔ variante (listă de 2–20) + dată de închidere; celelalte tipuri fără ele.
        $this->addSql("ALTER TABLE civic_subject ADD CONSTRAINT chk_civic_subject_poll CHECK (
            (type <> 'POLL' AND poll_options IS NULL AND poll_closes_at IS NULL)
            OR (type = 'POLL' AND poll_closes_at IS NOT NULL AND jsonb_typeof(poll_options) = 'array'
                AND jsonb_array_length(poll_options) BETWEEN 2 AND 20))");
        $this->addSql("INSERT INTO vote_type (code, min_value, max_value) VALUES ('CHOICE', 0, 19)");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM vote_type WHERE code = 'CHOICE'");
        $this->addSql('ALTER TABLE civic_subject DROP CONSTRAINT chk_civic_subject_poll');
        $this->addSql('ALTER TABLE subject_revision DROP poll_options');
        $this->addSql('ALTER TABLE civic_subject DROP poll_options, DROP poll_closes_at');
    }
}
