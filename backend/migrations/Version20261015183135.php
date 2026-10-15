<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261015183135 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Step 3 M.2: localizarea subiectului (si in revizie), linkuri in subject_media, tipul de vot LOCATION (ADR-0011)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE civic_subject ADD location_lat NUMERIC(9, 6) DEFAULT NULL');
        $this->addSql('ALTER TABLE civic_subject ADD location_lng NUMERIC(9, 6) DEFAULT NULL');
        $this->addSql('ALTER TABLE civic_subject ADD location_source VARCHAR(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE civic_subject ADD location_admin_confirmed BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE civic_subject ADD location_users_confirmed BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE civic_subject ADD location_confirmations INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE subject_media ADD url VARCHAR(2048) DEFAULT NULL');
        $this->addSql('ALTER TABLE subject_media ADD label VARCHAR(200) DEFAULT NULL');
        $this->addSql('ALTER TABLE subject_media ALTER width DROP NOT NULL');
        $this->addSql('ALTER TABLE subject_media ALTER height DROP NOT NULL');
        $this->addSql('ALTER TABLE subject_media ALTER byte_size DROP NOT NULL');
        $this->addSql('ALTER TABLE subject_revision ADD location_lat NUMERIC(9, 6) DEFAULT NULL');
        $this->addSql('ALTER TABLE subject_revision ADD location_lng NUMERIC(9, 6) DEFAULT NULL');
        $this->addSql('ALTER TABLE subject_revision ADD location_source VARCHAR(16) DEFAULT NULL');
        // ADR-0011 p.9: „Locul e corect?” Da/Nu; se ataseaza doar subiectelor cu pin (SubjectLocation).
        $this->addSql("INSERT INTO vote_type (code, min_value, max_value) VALUES ('LOCATION', 0, 1)");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM vote_type WHERE code = 'LOCATION'");
        $this->addSql('ALTER TABLE civic_subject DROP location_lat');
        $this->addSql('ALTER TABLE civic_subject DROP location_lng');
        $this->addSql('ALTER TABLE civic_subject DROP location_source');
        $this->addSql('ALTER TABLE civic_subject DROP location_admin_confirmed');
        $this->addSql('ALTER TABLE civic_subject DROP location_users_confirmed');
        $this->addSql('ALTER TABLE civic_subject DROP location_confirmations');
        $this->addSql('ALTER TABLE subject_media DROP url');
        $this->addSql('ALTER TABLE subject_media DROP label');
        $this->addSql('ALTER TABLE subject_media ALTER width SET NOT NULL');
        $this->addSql('ALTER TABLE subject_media ALTER height SET NOT NULL');
        $this->addSql('ALTER TABLE subject_media ALTER byte_size SET NOT NULL');
        $this->addSql('ALTER TABLE subject_revision DROP location_lat');
        $this->addSql('ALTER TABLE subject_revision DROP location_lng');
        $this->addSql('ALTER TABLE subject_revision DROP location_source');
    }
}
