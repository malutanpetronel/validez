<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Seo\SlugGenerator;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Step S.1 (ADR-0008): slug-uri pentru categorii și subiecte, istoric pentru URL-urile vechi.
 *
 * Datele existente primesc slug-uri cu aceleași reguli ca aplicația (App\Seo\SlugGenerator):
 * nodurile în ordinea adâncimii (părintele înaintea copiilor) și a pozițiilor, unic între frați,
 * `subiect` rezervat; subiectele în ordinea creării, unic global. Apoi coloanele devin NOT NULL.
 */
final class Version20261013150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'SEO: slug + slug_path on tree_node, slug on civic_subject, slug_redirect history (ADR-0008)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE slug_redirect (entity_type VARCHAR(16) NOT NULL, old_value VARCHAR(1024) NOT NULL, target_id UUID NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (entity_type, old_value))');
        $this->addSql('ALTER TABLE tree_node ADD slug VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE tree_node ADD slug_path VARCHAR(1024) DEFAULT NULL');
        $this->addSql('ALTER TABLE civic_subject ADD slug VARCHAR(100) DEFAULT NULL');

        $paths = [];
        $taken = [];
        $nodes = $this->connection->fetchAllAssociative(
            'SELECT id::text AS id, parent_id::text AS parent_id, name FROM tree_node ORDER BY nlevel(path), position, created_at, id',
        );
        foreach ($nodes as $node) {
            $key = $node['parent_id'] ?? '';
            $slug = SlugGenerator::firstFree(
                SlugGenerator::base($node['name'], SlugGenerator::NODE_FALLBACK),
                array_keys($taken[$key] ?? []),
                SlugGenerator::RESERVED_NODE_SLUGS,
            );
            $taken[$key][$slug] = true;
            $paths[$node['id']] = $node['parent_id'] === null ? $slug : $paths[$node['parent_id']].'/'.$slug;
            $this->addSql('UPDATE tree_node SET slug = ?, slug_path = ? WHERE id = ?', [$slug, $paths[$node['id']], $node['id']]);
        }

        $subjectSlugs = [];
        $subjects = $this->connection->fetchAllAssociative('SELECT id::text AS id, title FROM civic_subject ORDER BY created_at, id');
        foreach ($subjects as $subject) {
            $slug = SlugGenerator::firstFree(SlugGenerator::base($subject['title'], SlugGenerator::SUBJECT_FALLBACK), array_keys($subjectSlugs));
            $subjectSlugs[$slug] = true;
            $this->addSql('UPDATE civic_subject SET slug = ? WHERE id = ?', [$slug, $subject['id']]);
        }

        $this->addSql('ALTER TABLE tree_node ALTER slug SET NOT NULL');
        $this->addSql('ALTER TABLE tree_node ALTER slug_path SET NOT NULL');
        $this->addSql('ALTER TABLE civic_subject ALTER slug SET NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_tree_node_slug_path ON tree_node (slug_path)');
        $this->addSql('CREATE UNIQUE INDEX uniq_civic_subject_slug ON civic_subject (slug)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_civic_subject_slug');
        $this->addSql('DROP INDEX uniq_tree_node_slug_path');
        $this->addSql('ALTER TABLE civic_subject DROP slug');
        $this->addSql('ALTER TABLE tree_node DROP slug_path');
        $this->addSql('ALTER TABLE tree_node DROP slug');
        $this->addSql('DROP TABLE slug_redirect');
    }
}
