<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260505142305 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add identity_refresh_tokens table for server-side refresh token storage (Epic 13)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE identity_refresh_tokens (id VARCHAR(32) NOT NULL, user_id VARCHAR(32) NOT NULL, token_hash VARCHAR(64) NOT NULL, expires_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, user_agent VARCHAR(255) DEFAULT NULL, revoked_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_identity_refresh_tokens_user_revoked ON identity_refresh_tokens (user_id, revoked_at)');
        $this->addSql('CREATE UNIQUE INDEX uniq_identity_refresh_tokens_token_hash ON identity_refresh_tokens (token_hash)');
        $this->addSql('ALTER TABLE identity_refresh_tokens ADD CONSTRAINT fk_identity_refresh_tokens_user FOREIGN KEY (user_id) REFERENCES identity_users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE identity_refresh_tokens DROP CONSTRAINT fk_identity_refresh_tokens_user');
        $this->addSql('DROP TABLE identity_refresh_tokens');
    }
}
