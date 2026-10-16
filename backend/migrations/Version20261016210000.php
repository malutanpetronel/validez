<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ADR-0012 p.2, p.6, p.7 (G.1b): administrative_unit (ORM), boundary_set (ORM) si administrative_boundary
 * (doar SQL, exclusa din ORM prin schema_filter). Constrangerile pe care Doctrine nu le exprima sunt aici.
 * Fara date: nomenclatorul si limitele vin din importul G.1c.
 */
final class Version20261016210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'G.1b: administrative_unit, boundary_set, administrative_boundary (ADR-0012)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE administrative_unit (
            id UUID NOT NULL, code VARCHAR(16) NOT NULL, siruta INT DEFAULT NULL, level VARCHAR(16) NOT NULL,
            siruta_type SMALLINT DEFAULT NULL, path ltree NOT NULL, official_name VARCHAR(255) NOT NULL,
            display_name VARCHAR(255) NOT NULL, siruta_parent_code INT DEFAULT NULL, external_ids JSONB NOT NULL,
            nomenclature_version VARCHAR(64) DEFAULT NULL, retired_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
            created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            parent_id UUID DEFAULT NULL, PRIMARY KEY (id),
            CONSTRAINT chk_administrative_unit_level CHECK (level IN (\'COUNTRY\', \'COUNTY\', \'UAT\')),
            -- tara: cod propriu (RO), fara SIRUTA si fara parinte; judet/UAT: SIRUTA obligatoriu, codul = SIRUTA, cu parinte
            CONSTRAINT chk_administrative_unit_country CHECK (
                (level = \'COUNTRY\' AND siruta IS NULL AND parent_id IS NULL AND code ~ \'^[A-Z]{2}$\' AND siruta_type IS NULL)
                OR (level <> \'COUNTRY\' AND siruta > 0 AND parent_id IS NOT NULL AND code = siruta::text)),
            CONSTRAINT chk_administrative_unit_external_ids CHECK (jsonb_typeof(external_ids) = \'object\'),
            CONSTRAINT chk_administrative_unit_names CHECK (btrim(official_name) <> \'\' AND btrim(display_name) <> \'\'))');
        $this->addSql('CREATE INDEX idx_administrative_unit_parent ON administrative_unit (parent_id)');
        $this->addSql('CREATE INDEX idx_administrative_unit_path_gist ON administrative_unit USING GIST (path)');
        $this->addSql('CREATE UNIQUE INDEX uniq_administrative_unit_code ON administrative_unit (code)');
        $this->addSql('CREATE UNIQUE INDEX uniq_administrative_unit_siruta ON administrative_unit (siruta)');
        $this->addSql('ALTER TABLE administrative_unit ADD CONSTRAINT FK_C86D61BE727ACA70 FOREIGN KEY (parent_id) REFERENCES administrative_unit (id) ON DELETE RESTRICT NOT DEFERRABLE');

        $this->addSql('CREATE TABLE boundary_set (
            id UUID NOT NULL, source VARCHAR(255) NOT NULL, source_url TEXT NOT NULL, license VARCHAR(64) NOT NULL,
            data_version VARCHAR(64) NOT NULL, artifact_checksum VARCHAR(64) NOT NULL, declared_crs TEXT NOT NULL,
            source_srid INT NOT NULL, transformation TEXT NOT NULL, accuracy_status VARCHAR(16) NOT NULL,
            accuracy_meters NUMERIC(7, 2) DEFAULT NULL, uncertainty_distance_meters NUMERIC(7, 2) DEFAULT NULL,
            accuracy_justification TEXT DEFAULT NULL, accuracy_verified_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
            unit_count INT NOT NULL, imported_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            activated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, deactivated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
            PRIMARY KEY (id),
            CONSTRAINT chk_boundary_set_checksum CHECK (artifact_checksum ~ \'^[0-9a-f]{64}$\'),
            CONSTRAINT chk_boundary_set_srid CHECK (source_srid > 0),
            CONSTRAINT chk_boundary_set_unit_count CHECK (unit_count >= 0),
            CONSTRAINT chk_boundary_set_accuracy_status CHECK (accuracy_status IN (\'UNVERIFIED\', \'ACCEPTED\', \'REJECTED\')),
            CONSTRAINT chk_boundary_set_distances CHECK ((accuracy_meters IS NULL OR accuracy_meters >= 0) AND (uncertainty_distance_meters IS NULL OR uncertainty_distance_meters >= 0)),
            -- ADR-0012 p.2/p.6: acceptat = precizie, D, justificare si momentul verificarii
            CONSTRAINT chk_boundary_set_accepted CHECK (accuracy_status <> \'ACCEPTED\' OR (accuracy_meters IS NOT NULL
                AND uncertainty_distance_meters IS NOT NULL AND accuracy_justification IS NOT NULL AND accuracy_verified_at IS NOT NULL)),
            -- activ doar daca e acceptat; dezactivarea doar dupa activare
            CONSTRAINT chk_boundary_set_activation CHECK (activated_at IS NULL OR accuracy_status = \'ACCEPTED\'),
            CONSTRAINT chk_boundary_set_deactivation CHECK (deactivated_at IS NULL OR (activated_at IS NOT NULL AND deactivated_at >= activated_at)))');
        $this->addSql('CREATE UNIQUE INDEX uniq_boundary_set_artifact_checksum ON boundary_set (artifact_checksum)');
        // Un singur set activ.
        $this->addSql('CREATE UNIQUE INDEX uniq_boundary_set_single_active ON boundary_set ((true)) WHERE activated_at IS NOT NULL AND deactivated_at IS NULL');

        // Poligoanele: geometria originala, in cadrul sursei (SRID-ul declarat de set), si cea in WGS84, folosita la derivare.
        // geom e geom_source transformata cu transformarea inregistrata in set; reparata (ST_MakeValid) doar daca sursa
        // e invalida, cu repaired = true, iar originalul ramane in geom_source pentru diagnostic.
        $this->addSql('CREATE TABLE administrative_boundary (
            boundary_set_id UUID NOT NULL REFERENCES boundary_set (id) ON DELETE RESTRICT,
            administrative_unit_id UUID NOT NULL REFERENCES administrative_unit (id) ON DELETE RESTRICT,
            source_srid INT NOT NULL,
            geom_source geometry(MultiPolygon) NOT NULL,
            geom geometry(MultiPolygon, 4326) NOT NULL,
            repaired BOOLEAN NOT NULL DEFAULT false,
            PRIMARY KEY (boundary_set_id, administrative_unit_id),
            CONSTRAINT chk_administrative_boundary_source_srid CHECK (ST_SRID(geom_source) = source_srid),
            CONSTRAINT chk_administrative_boundary_valid CHECK (ST_IsValid(geom) AND NOT ST_IsEmpty(geom)))');
        $this->addSql('CREATE INDEX idx_administrative_boundary_geom ON administrative_boundary USING GIST (geom)');
        $this->addSql('CREATE INDEX idx_administrative_boundary_unit ON administrative_boundary (administrative_unit_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE administrative_boundary');
        $this->addSql('DROP TABLE boundary_set');
        $this->addSql('DROP TABLE administrative_unit');
    }
}
