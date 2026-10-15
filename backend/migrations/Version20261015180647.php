<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261015180647 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Step 3 M.1: subject_media - fotografiile subiectelor (ADR-0011)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE subject_media (id UUID NOT NULL, kind VARCHAR(16) NOT NULL, status VARCHAR(16) NOT NULL, position INT NOT NULL, width INT NOT NULL, height INT NOT NULL, byte_size INT NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, approved_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, removed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, subject_id UUID NOT NULL, uploaded_by_id UUID DEFAULT NULL, removed_by_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_subject_media_subject ON subject_media (subject_id, position)');
        $this->addSql('CREATE INDEX idx_subject_media_status ON subject_media (status)');
        $this->addSql('CREATE INDEX IDX_7BBF60D423EDC87 ON subject_media (subject_id)');
        $this->addSql('CREATE INDEX IDX_7BBF60D4A2B28FE8 ON subject_media (uploaded_by_id)');
        $this->addSql('CREATE INDEX IDX_7BBF60D42BD701DA ON subject_media (removed_by_id)');
        $this->addSql('ALTER TABLE subject_media ADD CONSTRAINT FK_7BBF60D423EDC87 FOREIGN KEY (subject_id) REFERENCES civic_subject (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE subject_media ADD CONSTRAINT FK_7BBF60D4A2B28FE8 FOREIGN KEY (uploaded_by_id) REFERENCES app_user (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE subject_media ADD CONSTRAINT FK_7BBF60D42BD701DA FOREIGN KEY (removed_by_id) REFERENCES app_user (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE subject_media DROP CONSTRAINT FK_7BBF60D423EDC87');
        $this->addSql('ALTER TABLE subject_media DROP CONSTRAINT FK_7BBF60D4A2B28FE8');
        $this->addSql('ALTER TABLE subject_media DROP CONSTRAINT FK_7BBF60D42BD701DA');
        $this->addSql('DROP TABLE subject_media');
    }
}
