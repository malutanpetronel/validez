<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * G.3 (ADR-0012 p.4–5, p.7): aria declarată a subiectului (`scope` + unitățile declarate) și instantaneul geografic
 * al reviziei. Subiectele și reviziile existente rămân cu scope NULL („arie nespecificată”), vizibile și votabile.
 */
final class Version20261118210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'G.3: civic_subject.scope, subject_administrative_unit, instantaneul geografic în subject_revision';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE subject_administrative_unit (subject_id UUID NOT NULL, unit_id UUID NOT NULL, PRIMARY KEY (subject_id, unit_id))');
        $this->addSql('CREATE INDEX IDX_EB047D2923EDC87 ON subject_administrative_unit (subject_id)');
        $this->addSql('CREATE INDEX IDX_EB047D29F8BD700D ON subject_administrative_unit (unit_id)');
        $this->addSql('ALTER TABLE subject_administrative_unit ADD CONSTRAINT FK_EB047D2923EDC87 FOREIGN KEY (subject_id) REFERENCES civic_subject (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE subject_administrative_unit ADD CONSTRAINT FK_EB047D29F8BD700D FOREIGN KEY (unit_id) REFERENCES administrative_unit (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE civic_subject ADD scope VARCHAR(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE civic_subject ADD area_completed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE subject_revision ADD scope VARCHAR(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE subject_revision ADD area_units JSONB DEFAULT NULL');
        $this->addSql('ALTER TABLE subject_revision ADD pin_admin JSONB DEFAULT NULL');
        $this->addSql('ALTER TABLE subject_revision ADD effective_area JSONB DEFAULT NULL');
        $this->addSql("ALTER TABLE civic_subject ADD CONSTRAINT chk_civic_subject_scope CHECK (scope IS NULL OR scope IN ('LOCAL', 'COUNTY', 'NATIONAL'))");
        $this->addSql("ALTER TABLE subject_revision ADD CONSTRAINT chk_subject_revision_scope CHECK (scope IS NULL OR scope IN ('LOCAL', 'COUNTY', 'NATIONAL'))");
        $this->addSql("ALTER TABLE subject_revision ADD CONSTRAINT chk_subject_revision_area CHECK ((scope IS NULL) = (area_units IS NULL AND effective_area IS NULL))");
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE subject_administrative_unit DROP CONSTRAINT FK_EB047D2923EDC87');
        $this->addSql('ALTER TABLE subject_administrative_unit DROP CONSTRAINT FK_EB047D29F8BD700D');
        $this->addSql('DROP TABLE subject_administrative_unit');
        $this->addSql('ALTER TABLE civic_subject DROP scope');
        $this->addSql('ALTER TABLE civic_subject DROP area_completed_at');
        $this->addSql('ALTER TABLE subject_revision DROP scope');
        $this->addSql('ALTER TABLE subject_revision DROP area_units');
        $this->addSql('ALTER TABLE subject_revision DROP pin_admin');
        $this->addSql('ALTER TABLE subject_revision DROP effective_area');
    }
}
