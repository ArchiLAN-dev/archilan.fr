<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 38.20: a game's introspection (default YAML, option table, location, item and item group names) moves off
 * `game` to `game_introspection`, which the Game entity loads lazily. Every list of games used to decode all of it
 * and ran out of memory once item names were added (v0.44.0).
 *
 * One row per game that has at least one of these values; the others get one the first time something is recorded.
 */
final class Version20261022200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Story 38.20: game introspection moved to game_introspection';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE game_introspection (game_id VARCHAR(32) NOT NULL, default_yaml TEXT DEFAULT NULL, option_types JSON DEFAULT NULL, location_names JSON DEFAULT NULL, item_names JSON DEFAULT NULL, item_groups JSON DEFAULT NULL, PRIMARY KEY (game_id))');
        $this->addSql('INSERT INTO game_introspection (game_id, default_yaml, option_types, location_names, item_names, item_groups)
            SELECT id, default_yaml, option_types, location_names, item_names, item_groups FROM game
            WHERE default_yaml IS NOT NULL OR option_types IS NOT NULL OR location_names IS NOT NULL OR item_names IS NOT NULL OR item_groups IS NOT NULL');
        $this->addSql('ALTER TABLE game ADD introspection_id VARCHAR(32) DEFAULT NULL');
        $this->addSql('UPDATE game SET introspection_id = id WHERE id IN (SELECT game_id FROM game_introspection)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_232B318C633F978F ON game (introspection_id)');
        $this->addSql('ALTER TABLE game ADD CONSTRAINT FK_232B318C633F978F FOREIGN KEY (introspection_id) REFERENCES game_introspection (game_id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE game DROP default_yaml');
        $this->addSql('ALTER TABLE game DROP option_types');
        $this->addSql('ALTER TABLE game DROP location_names');
        $this->addSql('ALTER TABLE game DROP item_names');
        $this->addSql('ALTER TABLE game DROP item_groups');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game ADD default_yaml TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE game ADD option_types JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE game ADD location_names JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE game ADD item_names JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE game ADD item_groups JSON DEFAULT NULL');
        $this->addSql('UPDATE game g SET default_yaml = i.default_yaml, option_types = i.option_types, location_names = i.location_names, item_names = i.item_names, item_groups = i.item_groups
            FROM game_introspection i WHERE i.game_id = g.introspection_id');
        $this->addSql('ALTER TABLE game DROP CONSTRAINT FK_232B318C633F978F');
        $this->addSql('DROP INDEX UNIQ_232B318C633F978F');
        $this->addSql('ALTER TABLE game DROP introspection_id');
        $this->addSql('DROP TABLE game_introspection');
    }
}
