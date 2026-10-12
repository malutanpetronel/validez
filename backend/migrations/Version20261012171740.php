<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Ulid;

/**
 * Step 4.b1 (ADR-0007): schema voturilor. Votarea nu e activă până la 4.b2–4.b4.
 *
 * - CHECK-urile, triggerul append-only și funcția lui nu sunt cunoscute de Doctrine: `migrations:diff` le ignoră.
 * - Subiectele existente primesc revizia 1 = starea găsită la migrare (nu conținutul de la creare)
 *   și tipurile de vot implicite după tipul subiectului (aceeași regulă ca App\Vote\DefaultVoteTypes).
 */
final class Version20261012171740 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Votes: vote types, revisions, private votes/history, append-only integrity log, results, consent';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE integrity_event_log (id UUID NOT NULL, sequence_number BIGINT NOT NULL, format_version SMALLINT NOT NULL, kind VARCHAR(32) NOT NULL, commitment BYTEA NOT NULL, previous_event_hash BYTEA DEFAULT NULL, event_hash BYTEA NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, subject_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_integrity_subject_sequence ON integrity_event_log (subject_id, sequence_number)');
        $this->addSql('CREATE UNIQUE INDEX uniq_integrity_event_hash ON integrity_event_log (event_hash)');
        $this->addSql('CREATE INDEX IDX_C18F25D23EDC87 ON integrity_event_log (subject_id)');
        $this->addSql('CREATE TABLE subject_revision (id UUID NOT NULL, number INT NOT NULL, type VARCHAR(32) NOT NULL, title VARCHAR(200) NOT NULL, description TEXT NOT NULL, cost_estimate NUMERIC(12, 2) DEFAULT NULL, cost_currency VARCHAR(3) DEFAULT NULL, cost_estimate_scope TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, subject_id UUID NOT NULL, created_by UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_subject_revision_number ON subject_revision (subject_id, number)');
        $this->addSql('CREATE INDEX IDX_551E55A423EDC87 ON subject_revision (subject_id)');
        $this->addSql('CREATE INDEX IDX_551E55A4DE12AB56 ON subject_revision (created_by)');
        $this->addSql('CREATE TABLE subject_vote_type (active BOOLEAN DEFAULT true NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, subject_id UUID NOT NULL, vote_type_code VARCHAR(32) NOT NULL, PRIMARY KEY (subject_id, vote_type_code))');
        $this->addSql('CREATE INDEX IDX_78ED323223EDC87 ON subject_vote_type (subject_id)');
        $this->addSql('CREATE INDEX IDX_78ED3232E2563255 ON subject_vote_type (vote_type_code)');
        $this->addSql('CREATE TABLE user_vote_history (id UUID NOT NULL, action VARCHAR(16) NOT NULL, value_before SMALLINT DEFAULT NULL, value_after SMALLINT DEFAULT NULL, nonce BYTEA NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, user_id UUID NOT NULL, subject_id UUID NOT NULL, vote_type_code VARCHAR(32) NOT NULL, revision_before_id UUID DEFAULT NULL, revision_after_id UUID DEFAULT NULL, integrity_event_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_user_vote_history_user ON user_vote_history (user_id)');
        $this->addSql('CREATE INDEX idx_user_vote_history_event ON user_vote_history (integrity_event_id)');
        $this->addSql('CREATE INDEX IDX_2CC453D123EDC87 ON user_vote_history (subject_id)');
        $this->addSql('CREATE INDEX IDX_2CC453D1E2563255 ON user_vote_history (vote_type_code)');
        $this->addSql('CREATE INDEX IDX_2CC453D176F4BDCE ON user_vote_history (revision_before_id)');
        $this->addSql('CREATE INDEX IDX_2CC453D1420E05C3 ON user_vote_history (revision_after_id)');
        $this->addSql('CREATE TABLE vote (id UUID NOT NULL, value SMALLINT NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, user_id UUID NOT NULL, subject_id UUID NOT NULL, vote_type_code VARCHAR(32) NOT NULL, revision_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_vote_user ON vote (user_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_vote_subject_user_type ON vote (subject_id, user_id, vote_type_code)');
        $this->addSql('CREATE INDEX IDX_5A10856423EDC87 ON vote (subject_id)');
        $this->addSql('CREATE INDEX IDX_5A108564E2563255 ON vote (vote_type_code)');
        $this->addSql('CREATE INDEX IDX_5A1085641DFA7C8F ON vote (revision_id)');
        $this->addSql('CREATE TABLE vote_consent (id UUID NOT NULL, notice_version VARCHAR(32) NOT NULL, granted_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, withdrawn_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_vote_consent_user ON vote_consent (user_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_vote_consent_active ON vote_consent (user_id) WHERE (withdrawn_at IS NULL)');
        $this->addSql('CREATE TABLE vote_result (current_counts JSONB DEFAULT \'{}\' NOT NULL, current_total INT DEFAULT 0 NOT NULL, stale_total INT DEFAULT 0 NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, subject_id UUID NOT NULL, vote_type_code VARCHAR(32) NOT NULL, PRIMARY KEY (subject_id, vote_type_code))');
        $this->addSql('CREATE INDEX IDX_1F8DB34923EDC87 ON vote_result (subject_id)');
        $this->addSql('CREATE INDEX IDX_1F8DB349E2563255 ON vote_result (vote_type_code)');
        $this->addSql('CREATE TABLE vote_type (code VARCHAR(32) NOT NULL, min_value SMALLINT NOT NULL, max_value SMALLINT NOT NULL, PRIMARY KEY (code))');
        $this->addSql('ALTER TABLE integrity_event_log ADD CONSTRAINT FK_C18F25D23EDC87 FOREIGN KEY (subject_id) REFERENCES civic_subject (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE subject_revision ADD CONSTRAINT FK_551E55A423EDC87 FOREIGN KEY (subject_id) REFERENCES civic_subject (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE subject_revision ADD CONSTRAINT FK_551E55A4DE12AB56 FOREIGN KEY (created_by) REFERENCES app_user (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE subject_vote_type ADD CONSTRAINT FK_78ED323223EDC87 FOREIGN KEY (subject_id) REFERENCES civic_subject (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE subject_vote_type ADD CONSTRAINT FK_78ED3232E2563255 FOREIGN KEY (vote_type_code) REFERENCES vote_type (code) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE user_vote_history ADD CONSTRAINT FK_2CC453D1A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE user_vote_history ADD CONSTRAINT FK_2CC453D123EDC87 FOREIGN KEY (subject_id) REFERENCES civic_subject (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE user_vote_history ADD CONSTRAINT FK_2CC453D1E2563255 FOREIGN KEY (vote_type_code) REFERENCES vote_type (code) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE user_vote_history ADD CONSTRAINT FK_2CC453D176F4BDCE FOREIGN KEY (revision_before_id) REFERENCES subject_revision (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE user_vote_history ADD CONSTRAINT FK_2CC453D1420E05C3 FOREIGN KEY (revision_after_id) REFERENCES subject_revision (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE user_vote_history ADD CONSTRAINT FK_2CC453D14B263847 FOREIGN KEY (integrity_event_id) REFERENCES integrity_event_log (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE vote ADD CONSTRAINT FK_5A108564A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE vote ADD CONSTRAINT FK_5A10856423EDC87 FOREIGN KEY (subject_id) REFERENCES civic_subject (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE vote ADD CONSTRAINT FK_5A108564E2563255 FOREIGN KEY (vote_type_code) REFERENCES vote_type (code) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE vote ADD CONSTRAINT FK_5A1085641DFA7C8F FOREIGN KEY (revision_id) REFERENCES subject_revision (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE vote_consent ADD CONSTRAINT FK_E8A05788A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE vote_result ADD CONSTRAINT FK_1F8DB34923EDC87 FOREIGN KEY (subject_id) REFERENCES civic_subject (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE vote_result ADD CONSTRAINT FK_1F8DB349E2563255 FOREIGN KEY (vote_type_code) REFERENCES vote_type (code) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE civic_subject ADD revision_number INT DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE civic_subject ADD first_voted_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');

        // Constrângeri locale explicite (ADR-0007, observațiile la 4.b1).
        $this->addSql('ALTER TABLE vote_type ADD CONSTRAINT chk_vote_type_range CHECK (min_value <= max_value)');
        $this->addSql('ALTER TABLE civic_subject ADD CONSTRAINT chk_civic_subject_revision_number CHECK (revision_number >= 1)');
        $this->addSql('ALTER TABLE subject_revision ADD CONSTRAINT chk_subject_revision_number CHECK (number >= 1)');
        $this->addSql("ALTER TABLE integrity_event_log ADD CONSTRAINT chk_integrity_kind CHECK (kind IN ('VOTE_SET', 'VOTE_WITHDRAWN'))");
        $this->addSql('ALTER TABLE integrity_event_log ADD CONSTRAINT chk_integrity_sequence CHECK (sequence_number >= 1)');
        $this->addSql('ALTER TABLE integrity_event_log ADD CONSTRAINT chk_integrity_format CHECK (format_version >= 1)');
        $this->addSql('ALTER TABLE integrity_event_log ADD CONSTRAINT chk_integrity_commitment CHECK (octet_length(commitment) = 32)');
        $this->addSql('ALTER TABLE integrity_event_log ADD CONSTRAINT chk_integrity_event_hash CHECK (octet_length(event_hash) = 32)');
        // `IS NOT NULL` explicit: octet_length(NULL) = 32 dă NULL, iar un CHECK cu rezultat NULL trece.
        $this->addSql('ALTER TABLE integrity_event_log ADD CONSTRAINT chk_integrity_previous_hash CHECK ((sequence_number = 1 AND previous_event_hash IS NULL) OR (sequence_number > 1 AND previous_event_hash IS NOT NULL AND octet_length(previous_event_hash) = 32))');
        $this->addSql('ALTER TABLE user_vote_history ADD CONSTRAINT chk_user_vote_history_nonce CHECK (octet_length(nonce) = 32)');
        $this->addSql("ALTER TABLE user_vote_history ADD CONSTRAINT chk_user_vote_history_action CHECK ((action = 'WITHDRAW' AND value_after IS NULL AND revision_after_id IS NULL) OR (action IN ('VOTE', 'CHANGE', 'RECONFIRM') AND value_after IS NOT NULL AND revision_after_id IS NOT NULL))");
        $this->addSql('ALTER TABLE vote_result ADD CONSTRAINT chk_vote_result_totals CHECK (current_total >= 0 AND stale_total >= 0)');
        $this->addSql("ALTER TABLE vote_result ADD CONSTRAINT chk_vote_result_counts_object CHECK (jsonb_typeof(current_counts) = 'object')");

        // Append-only: UPDATE și DELETE respinse. TRUNCATE e un eveniment distinct și rămâne disponibil testelor;
        // în producție rolul aplicației nu trebuie să poată face TRUNCATE sau să elimine triggerul.
        $this->addSql(<<<'SQL'
            CREATE FUNCTION integrity_event_log_append_only() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'integrity_event_log is append-only: % rejected', TG_OP;
            END
            $$
            SQL);
        $this->addSql('CREATE TRIGGER trg_integrity_event_log_append_only BEFORE UPDATE OR DELETE ON integrity_event_log FOR EACH ROW EXECUTE FUNCTION integrity_event_log_append_only()');

        // Seed tipuri de vot (ADR-0007 §1).
        $this->addSql("INSERT INTO vote_type (code, min_value, max_value) VALUES ('VALIDITY', 0, 1), ('YES_NO', 0, 1), ('IMPORTANCE', 1, 5)");

        // Tipuri implicite pentru subiectele existente.
        $this->addSql(<<<'SQL'
            INSERT INTO subject_vote_type (subject_id, vote_type_code, active, created_at)
            SELECT s.id, d.code, true, NOW()
            FROM civic_subject s
            JOIN (VALUES ('ISSUE', 'VALIDITY'), ('ISSUE', 'IMPORTANCE'), ('PROPOSAL', 'YES_NO'), ('PROPOSAL', 'IMPORTANCE'), ('PETITION', 'YES_NO')) AS d(subject_type, code)
              ON d.subject_type = s.type
            SQL);

        // Revizia 1 pentru subiectele existente: starea găsită acum, cu estimarea brută (protejată la afișare după tip).
        foreach ($this->connection->fetchFirstColumn('SELECT id FROM civic_subject ORDER BY created_at, id') as $subjectId) {
            $this->addSql(
                'INSERT INTO subject_revision (id, subject_id, number, type, title, description, cost_estimate, cost_currency, cost_estimate_scope, created_by, created_at)
                 SELECT ?, s.id, 1, s.type, s.title, s.description, s.cost_estimate, s.cost_currency, s.cost_estimate_scope, s.author_id, NOW()
                 FROM civic_subject s WHERE s.id = ?',
                [(new Ulid())->toRfc4122(), $subjectId],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE integrity_event_log DROP CONSTRAINT FK_C18F25D23EDC87');
        $this->addSql('ALTER TABLE subject_revision DROP CONSTRAINT FK_551E55A423EDC87');
        $this->addSql('ALTER TABLE subject_revision DROP CONSTRAINT FK_551E55A4DE12AB56');
        $this->addSql('ALTER TABLE subject_vote_type DROP CONSTRAINT FK_78ED323223EDC87');
        $this->addSql('ALTER TABLE subject_vote_type DROP CONSTRAINT FK_78ED3232E2563255');
        $this->addSql('ALTER TABLE user_vote_history DROP CONSTRAINT FK_2CC453D1A76ED395');
        $this->addSql('ALTER TABLE user_vote_history DROP CONSTRAINT FK_2CC453D123EDC87');
        $this->addSql('ALTER TABLE user_vote_history DROP CONSTRAINT FK_2CC453D1E2563255');
        $this->addSql('ALTER TABLE user_vote_history DROP CONSTRAINT FK_2CC453D176F4BDCE');
        $this->addSql('ALTER TABLE user_vote_history DROP CONSTRAINT FK_2CC453D1420E05C3');
        $this->addSql('ALTER TABLE user_vote_history DROP CONSTRAINT FK_2CC453D14B263847');
        $this->addSql('ALTER TABLE vote DROP CONSTRAINT FK_5A108564A76ED395');
        $this->addSql('ALTER TABLE vote DROP CONSTRAINT FK_5A10856423EDC87');
        $this->addSql('ALTER TABLE vote DROP CONSTRAINT FK_5A108564E2563255');
        $this->addSql('ALTER TABLE vote DROP CONSTRAINT FK_5A1085641DFA7C8F');
        $this->addSql('ALTER TABLE vote_consent DROP CONSTRAINT FK_E8A05788A76ED395');
        $this->addSql('ALTER TABLE vote_result DROP CONSTRAINT FK_1F8DB34923EDC87');
        $this->addSql('ALTER TABLE vote_result DROP CONSTRAINT FK_1F8DB349E2563255');
        $this->addSql('DROP TABLE integrity_event_log');
        $this->addSql('DROP FUNCTION integrity_event_log_append_only()');
        $this->addSql('DROP TABLE subject_revision');
        $this->addSql('DROP TABLE subject_vote_type');
        $this->addSql('DROP TABLE user_vote_history');
        $this->addSql('DROP TABLE vote');
        $this->addSql('DROP TABLE vote_consent');
        $this->addSql('DROP TABLE vote_result');
        $this->addSql('DROP TABLE vote_type');
        $this->addSql('ALTER TABLE civic_subject DROP revision_number');
        $this->addSql('ALTER TABLE civic_subject DROP first_voted_at');
    }
}
