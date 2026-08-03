<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Last-known players state per session (story 17.21): one row per session, overwritten on every
 * bridge players-push, so the Progression tab stays viewable when the bridge is unreachable.
 */
final class Version20260730100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create session_players_snapshot for the bridge-down Progression fallback (story 17.21)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE session_players_snapshot (
                session_id VARCHAR(64) NOT NULL,
                payload JSON NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                PRIMARY KEY (session_id)
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE session_players_snapshot');
    }
}
