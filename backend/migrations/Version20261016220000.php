<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Decizie 16.10.2026 (ADR-0012 p.2): statusul PROVISIONAL pentru seturile de limite — activabil, cu precizia,
 * distanța D și justificarea obligatorii, ca la ACCEPTED.
 */
final class Version20261016220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'G.1c: boundary_set.accuracy_status PROVISIONAL (ADR-0012)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE boundary_set DROP CONSTRAINT chk_boundary_set_accuracy_status');
        $this->addSql('ALTER TABLE boundary_set DROP CONSTRAINT chk_boundary_set_accepted');
        $this->addSql('ALTER TABLE boundary_set DROP CONSTRAINT chk_boundary_set_activation');
        $this->addSql("ALTER TABLE boundary_set ADD CONSTRAINT chk_boundary_set_accuracy_status CHECK (accuracy_status IN ('UNVERIFIED', 'PROVISIONAL', 'ACCEPTED', 'REJECTED'))");
        $this->addSql("ALTER TABLE boundary_set ADD CONSTRAINT chk_boundary_set_accepted CHECK (accuracy_status NOT IN ('ACCEPTED', 'PROVISIONAL') OR (accuracy_meters IS NOT NULL
            AND uncertainty_distance_meters IS NOT NULL AND accuracy_justification IS NOT NULL AND accuracy_verified_at IS NOT NULL))");
        $this->addSql("ALTER TABLE boundary_set ADD CONSTRAINT chk_boundary_set_activation CHECK (activated_at IS NULL OR accuracy_status IN ('ACCEPTED', 'PROVISIONAL'))");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE boundary_set SET accuracy_status = 'UNVERIFIED', activated_at = NULL, deactivated_at = NULL WHERE accuracy_status = 'PROVISIONAL'");
        $this->addSql('ALTER TABLE boundary_set DROP CONSTRAINT chk_boundary_set_accuracy_status');
        $this->addSql('ALTER TABLE boundary_set DROP CONSTRAINT chk_boundary_set_accepted');
        $this->addSql('ALTER TABLE boundary_set DROP CONSTRAINT chk_boundary_set_activation');
        $this->addSql("ALTER TABLE boundary_set ADD CONSTRAINT chk_boundary_set_accuracy_status CHECK (accuracy_status IN ('UNVERIFIED', 'ACCEPTED', 'REJECTED'))");
        $this->addSql("ALTER TABLE boundary_set ADD CONSTRAINT chk_boundary_set_accepted CHECK (accuracy_status <> 'ACCEPTED' OR (accuracy_meters IS NOT NULL
            AND uncertainty_distance_meters IS NOT NULL AND accuracy_justification IS NOT NULL AND accuracy_verified_at IS NOT NULL))");
        $this->addSql("ALTER TABLE boundary_set ADD CONSTRAINT chk_boundary_set_activation CHECK (activated_at IS NULL OR accuracy_status = 'ACCEPTED')");
    }
}
