<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 31.6 - community install-tutorial contributions (pending/approved/rejected).
 */
final class Version20260619120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create game_tutorial_contribution (community tutorial submissions, story 31.6).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE game_tutorial_contribution (id VARCHAR(32) NOT NULL, author_id VARCHAR(32) NOT NULL, game_id VARCHAR(32) DEFAULT NULL, proposed_game_name VARCHAR(160) DEFAULT NULL, steps JSON NOT NULL, message TEXT DEFAULT NULL, status VARCHAR(16) NOT NULL, reviewed_by VARCHAR(32) DEFAULT NULL, reviewed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, rejection_reason TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_contribution_author ON game_tutorial_contribution (author_id)');
        $this->addSql('CREATE INDEX idx_contribution_status ON game_tutorial_contribution (status)');
        $this->addSql('ALTER TABLE game_tutorial_contribution ADD CONSTRAINT fk_contribution_game FOREIGN KEY (game_id) REFERENCES game (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE game_tutorial_contribution');
    }
}
