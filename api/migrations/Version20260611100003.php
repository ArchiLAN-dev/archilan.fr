<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260611100003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Refresh tokens: add family_id + replaced_by_token_hash for per-family revocation and reuse grace (story 13.8)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE refresh_token ADD COLUMN family_id VARCHAR(32) DEFAULT NULL');
        $this->addSql('ALTER TABLE refresh_token ADD COLUMN replaced_by_token_hash VARCHAR(64) DEFAULT NULL');
        // Backfill: each existing token becomes its own single-token family.
        $this->addSql('UPDATE refresh_token SET family_id = id WHERE family_id IS NULL');
        $this->addSql('CREATE INDEX idx_identity_refresh_tokens_family ON refresh_token (family_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_identity_refresh_tokens_family');
        $this->addSql('ALTER TABLE refresh_token DROP COLUMN replaced_by_token_hash');
        $this->addSql('ALTER TABLE refresh_token DROP COLUMN family_id');
    }
}
