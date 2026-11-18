<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * AI.2 (ADR-0014): setările AI (un singur rând, oprit implicit) și jurnalul cererilor. Promptul NULL = cel implicit
 * din AiSettings::DEFAULT_PROMPT; modelele sunt fixe și se schimbă doar printr-o migrare.
 */
final class Version20261118120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'AI.2: ai_settings (rând unic) și ai_request (jurnal) — ADR-0014';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE ai_settings (
            id INT NOT NULL,
            enabled BOOLEAN NOT NULL,
            provider VARCHAR(20) NOT NULL,
            model_anthropic VARCHAR(100) NOT NULL,
            model_openai VARCHAR(100) NOT NULL,
            prompt TEXT DEFAULT NULL,
            language VARCHAR(10) NOT NULL,
            model_prices JSONB NOT NULL,
            daily_limit_per_admin INT NOT NULL,
            daily_requests_cap INT NOT NULL,
            daily_usd_cap NUMERIC(10, 2) NOT NULL,
            monthly_usd_cap NUMERIC(10, 2) NOT NULL,
            updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            PRIMARY KEY (id),
            CONSTRAINT chk_ai_settings_single CHECK (id = 1)
        )');
        $this->addSql("INSERT INTO ai_settings (id, enabled, provider, model_anthropic, model_openai, prompt, language, model_prices,
            daily_limit_per_admin, daily_requests_cap, daily_usd_cap, monthly_usd_cap, updated_at)
            VALUES (1, false, 'anthropic', 'claude-haiku-5-5', 'gpt-6-luna', NULL, 'română',
            '{\"claude-haiku-5-5\": {\"input\": 0.1, \"output\": 0.5}, \"gpt-6-luna\": {\"input\": 0.1, \"output\": 0.5}}',
            30, 30, 1.00, 10.00, NOW())");

        $this->addSql('CREATE TABLE ai_request (
            id UUID NOT NULL,
            requester_id UUID DEFAULT NULL,
            provider VARCHAR(20) NOT NULL,
            model VARCHAR(100) NOT NULL,
            purpose VARCHAR(30) NOT NULL,
            status VARCHAR(16) NOT NULL,
            request_hash VARCHAR(64) NOT NULL,
            input_tokens INT DEFAULT NULL,
            output_tokens INT DEFAULT NULL,
            cost_usd NUMERIC(12, 6) DEFAULT NULL,
            duration_ms INT DEFAULT NULL,
            response JSONB DEFAULT NULL,
            error VARCHAR(500) DEFAULT NULL,
            created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            finished_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
            PRIMARY KEY (id)
        )');
        $this->addSql('CREATE INDEX idx_ai_request_hash ON ai_request (request_hash)');
        $this->addSql('CREATE INDEX idx_ai_request_created ON ai_request (created_at)');
        $this->addSql('CREATE INDEX idx_ai_request_requester ON ai_request (requester_id, created_at)');
        $this->addSql('ALTER TABLE ai_request ADD CONSTRAINT fk_ai_request_requester FOREIGN KEY (requester_id) REFERENCES app_user (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE ai_request');
        $this->addSql('DROP TABLE ai_settings');
    }
}
