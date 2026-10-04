<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261004185749 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Step 2: civic_subject (nod + autor RESTRICT, type, visibility, stage)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE civic_subject (id UUID NOT NULL, type VARCHAR(32) NOT NULL, title VARCHAR(200) NOT NULL, description TEXT NOT NULL, visibility VARCHAR(16) NOT NULL, stage VARCHAR(16) NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, node_id UUID NOT NULL, author_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_civic_subject_node_created ON civic_subject (node_id, created_at)');
        $this->addSql('CREATE INDEX IDX_BD99AC8E460D9FD7 ON civic_subject (node_id)');
        $this->addSql('CREATE INDEX IDX_BD99AC8EF675F31B ON civic_subject (author_id)');
        $this->addSql('ALTER TABLE civic_subject ADD CONSTRAINT FK_BD99AC8E460D9FD7 FOREIGN KEY (node_id) REFERENCES tree_node (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE civic_subject ADD CONSTRAINT FK_BD99AC8EF675F31B FOREIGN KEY (author_id) REFERENCES app_user (id) ON DELETE RESTRICT NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE civic_subject DROP CONSTRAINT FK_BD99AC8E460D9FD7');
        $this->addSql('ALTER TABLE civic_subject DROP CONSTRAINT FK_BD99AC8EF675F31B');
        $this->addSql('DROP TABLE civic_subject');
    }
}
