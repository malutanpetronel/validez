<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Step 1.2 — TreeNode (ADR-0001): adjacency list + ltree.
 *
 * Scrisa manual peste SQL-ul generat de Doctrine, pentru ce maparea nu poate exprima:
 * - index GiST pe path;
 * - UNIQUE NULLS NOT DISTINCT (parent_id, position) DEFERRABLE INITIALLY DEFERRED:
 *   unicitatea acopera si radacinile (parent_id NULL) si e verificata la commit,
 *   ca reordonarea fratilor intr-o tranzactie sa nu se blocheze la pasi intermediari;
 * - CHECK position >= 0 si CHECK fara autoreferinta directa (ciclurile lungi: serviciul din 1.3).
 * Numele indexurilor sunt cele din mapare, ca doctrine:migrations:diff sa ramana gol.
 */
final class Version20261003204935 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'TreeNode: tabela tree_node cu path ltree (GiST), unicitate deferred pe (parent_id, position), FK RESTRICT';
    }

    public function up(Schema $schema): void
    {
        // Sursa de adevar pentru extensie (initdb o creeaza doar la prima pornire a volumului Docker).
        $this->addSql('CREATE EXTENSION IF NOT EXISTS ltree');

        $this->addSql(<<<'SQL'
            CREATE TABLE tree_node (
                id UUID NOT NULL,
                parent_id UUID DEFAULT NULL,
                path ltree NOT NULL,
                name VARCHAR(255) NOT NULL,
                position INT NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT chk_tree_node_position_non_negative CHECK (position >= 0),
                CONSTRAINT chk_tree_node_not_own_parent CHECK (parent_id IS NULL OR parent_id <> id)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_tree_node_path_gist ON tree_node USING GIST (path)');
        $this->addSql('ALTER TABLE tree_node ADD CONSTRAINT uniq_tree_node_parent_position UNIQUE NULLS NOT DISTINCT (parent_id, position) DEFERRABLE INITIALLY DEFERRED');
        $this->addSql('CREATE INDEX IDX_3AFC8272727ACA70 ON tree_node (parent_id)');
        $this->addSql('ALTER TABLE tree_node ADD CONSTRAINT FK_3AFC8272727ACA70 FOREIGN KEY (parent_id) REFERENCES tree_node (id) ON DELETE RESTRICT NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // Extensia ltree ramane: poate fi folosita si de alte tabele.
        $this->addSql('DROP TABLE tree_node');
    }
}
