<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261008120000 extends AbstractMigration
{
    public function getDescription(): string { return 'Initial contribution moderation and category suggestions'; }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user ADD direct_publishing_revoked BOOLEAN DEFAULT FALSE NOT NULL');
        $this->addSql('ALTER TABLE civic_subject ADD approved_contribution BOOLEAN DEFAULT FALSE NOT NULL');
        $this->addSql("CREATE TABLE category_suggestion (id UUID NOT NULL PRIMARY KEY, author_id UUID NOT NULL REFERENCES app_user(id) ON DELETE CASCADE, parent_id UUID DEFAULT NULL REFERENCES tree_node(id) ON DELETE SET NULL, name VARCHAR(120) NOT NULL, reason TEXT NOT NULL, status VARCHAR(16) NOT NULL, node_id UUID DEFAULT NULL REFERENCES tree_node(id) ON DELETE SET NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL)");
        $this->addSql('CREATE INDEX idx_category_suggestion_author ON category_suggestion(author_id)');
        $this->addSql('CREATE INDEX idx_category_suggestion_parent ON category_suggestion(parent_id)');
        $this->addSql('CREATE INDEX idx_category_suggestion_node ON category_suggestion(node_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE civic_subject SET visibility = 'HIDDEN' WHERE visibility = 'PENDING'");
        $this->addSql('DROP TABLE category_suggestion');
        $this->addSql('ALTER TABLE civic_subject DROP approved_contribution');
        $this->addSql('ALTER TABLE app_user DROP direct_publishing_revoked');
    }
}
