<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260504160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename tables to remove redundant/module-name prefixes';
    }

    public function up(Schema $schema): void
    {
        // Rename tables
        $this->addSql('ALTER TABLE events_events RENAME TO events');
        $this->addSql('ALTER TABLE registrations_registrations RENAME TO event_registrations');
        $this->addSql('ALTER TABLE sessions_sessions RENAME TO archipelago_sessions');
        $this->addSql('ALTER TABLE sessions_slots RENAME TO archipelago_session_slots');
        $this->addSql('ALTER TABLE game_selection_games RENAME TO games');

        // Rename primary key indexes (PostgreSQL names them {tablename}_pkey automatically)
        $this->addSql('ALTER INDEX events_events_pkey RENAME TO events_pkey');
        $this->addSql('ALTER INDEX registrations_registrations_pkey RENAME TO event_registrations_pkey');
        $this->addSql('ALTER INDEX sessions_sessions_pkey RENAME TO archipelago_sessions_pkey');
        $this->addSql('ALTER INDEX sessions_slots_pkey RENAME TO archipelago_session_slots_pkey');
        $this->addSql('ALTER INDEX game_selection_games_pkey RENAME TO games_pkey');

        // Rename unique constraints / unique indexes
        $this->addSql('ALTER INDEX uniq_registrations_event_user RENAME TO uniq_event_registrations_event_user');
        $this->addSql('ALTER INDEX uniq_game_selection_games_slug RENAME TO uniq_games_slug');
    }

    public function down(Schema $schema): void
    {
        // Rename tables back
        $this->addSql('ALTER TABLE events RENAME TO events_events');
        $this->addSql('ALTER TABLE event_registrations RENAME TO registrations_registrations');
        $this->addSql('ALTER TABLE archipelago_sessions RENAME TO sessions_sessions');
        $this->addSql('ALTER TABLE archipelago_session_slots RENAME TO sessions_slots');
        $this->addSql('ALTER TABLE games RENAME TO game_selection_games');

        // Rename primary key indexes back
        $this->addSql('ALTER INDEX events_pkey RENAME TO events_events_pkey');
        $this->addSql('ALTER INDEX event_registrations_pkey RENAME TO registrations_registrations_pkey');
        $this->addSql('ALTER INDEX archipelago_sessions_pkey RENAME TO sessions_sessions_pkey');
        $this->addSql('ALTER INDEX archipelago_session_slots_pkey RENAME TO sessions_slots_pkey');
        $this->addSql('ALTER INDEX games_pkey RENAME TO game_selection_games_pkey');

        // Rename unique constraints / unique indexes back
        $this->addSql('ALTER INDEX uniq_event_registrations_event_user RENAME TO uniq_registrations_event_user');
        $this->addSql('ALTER INDEX uniq_games_slug RENAME TO uniq_game_selection_games_slug');
    }
}
