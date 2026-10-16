<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * G.2 (ADR-0012 p.6): încadrarea administrativă curentă a pinului, pe civic_subject. Fără date: subiectele
 * existente cu pin se încadrează cu app:geo:classify-pins.
 */
final class Version20261016230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'G.2: civic_subject.pin_admin_* (încadrarea pinului, ADR-0012 p.6)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE civic_subject ADD pin_admin_state VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE civic_subject ADD pin_admin_candidates JSONB DEFAULT NULL');
        $this->addSql('ALTER TABLE civic_subject ADD pin_admin_computed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE civic_subject ADD pin_admin_resolved_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE civic_subject ADD pin_admin_unit_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE civic_subject ADD pin_admin_boundary_set_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE civic_subject ADD pin_admin_resolved_by_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE civic_subject ADD CONSTRAINT FK_BD99AC8EA4462A6C FOREIGN KEY (pin_admin_unit_id) REFERENCES administrative_unit (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE civic_subject ADD CONSTRAINT FK_BD99AC8E5F0EBFFE FOREIGN KEY (pin_admin_boundary_set_id) REFERENCES boundary_set (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE civic_subject ADD CONSTRAINT FK_BD99AC8E59F81EBD FOREIGN KEY (pin_admin_resolved_by_id) REFERENCES app_user (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_BD99AC8EA4462A6C ON civic_subject (pin_admin_unit_id)');
        $this->addSql('CREATE INDEX IDX_BD99AC8E5F0EBFFE ON civic_subject (pin_admin_boundary_set_id)');
        $this->addSql('CREATE INDEX IDX_BD99AC8E59F81EBD ON civic_subject (pin_admin_resolved_by_id)');
        // Contractul stărilor (ADR-0012 p.6): fără pin nu există încadrare; o încadrare are setul și momentul calculului;
        // UAT-ul e determinat exact la RESOLVED / RESOLVED_MANUALLY; rezolvarea manuală are autorul și momentul ei.
        $this->addSql("ALTER TABLE civic_subject ADD CONSTRAINT chk_civic_subject_pin_admin CHECK (
            (pin_admin_state IS NULL AND pin_admin_unit_id IS NULL AND pin_admin_candidates IS NULL AND pin_admin_boundary_set_id IS NULL
                AND pin_admin_computed_at IS NULL AND pin_admin_resolved_by_id IS NULL AND pin_admin_resolved_at IS NULL)
            OR (pin_admin_state IN ('RESOLVED', 'RESOLVED_MANUALLY', 'AMBIGUOUS', 'UNMATCHED', 'OUTSIDE')
                AND location_lat IS NOT NULL AND pin_admin_boundary_set_id IS NOT NULL AND pin_admin_computed_at IS NOT NULL
                AND jsonb_typeof(pin_admin_candidates) = 'array'
                AND (pin_admin_unit_id IS NOT NULL) = (pin_admin_state IN ('RESOLVED', 'RESOLVED_MANUALLY'))
                AND (pin_admin_resolved_at IS NOT NULL) = (pin_admin_state = 'RESOLVED_MANUALLY')))");
        $this->addSql("CREATE INDEX idx_civic_subject_pin_admin_open ON civic_subject (pin_admin_state) WHERE pin_admin_state IN ('AMBIGUOUS', 'UNMATCHED', 'OUTSIDE')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_civic_subject_pin_admin_open');
        $this->addSql('ALTER TABLE civic_subject DROP CONSTRAINT chk_civic_subject_pin_admin');
        $this->addSql('ALTER TABLE civic_subject DROP CONSTRAINT FK_BD99AC8EA4462A6C');
        $this->addSql('ALTER TABLE civic_subject DROP CONSTRAINT FK_BD99AC8E5F0EBFFE');
        $this->addSql('ALTER TABLE civic_subject DROP CONSTRAINT FK_BD99AC8E59F81EBD');
        $this->addSql('DROP INDEX IDX_BD99AC8EA4462A6C');
        $this->addSql('DROP INDEX IDX_BD99AC8E5F0EBFFE');
        $this->addSql('DROP INDEX IDX_BD99AC8E59F81EBD');
        $this->addSql('ALTER TABLE civic_subject DROP pin_admin_state, DROP pin_admin_candidates, DROP pin_admin_computed_at, DROP pin_admin_resolved_at, DROP pin_admin_unit_id, DROP pin_admin_boundary_set_id, DROP pin_admin_resolved_by_id');
    }
}
