<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The lists a player keeps on ArchiLAN, independently of Steam (story 28.13).
 *
 * The Steam coupling can only recognise titles carrying a `steamAppId`, which most of this catalog
 * does not - a GameCube or SNES game could never be marked owned. The (user_id, game_id, kind)
 * triple is the primary key, so adding twice is idempotent at the storage level, and a further list
 * costs a `kind` value rather than a second table with the same four columns.
 */
final class Version20260803180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create user_game_list for the player-kept game lists (story 28.13)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE user_game_list (
                user_id VARCHAR(32) NOT NULL,
                game_id VARCHAR(32) NOT NULL,
                kind VARCHAR(16) NOT NULL,
                marked_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                PRIMARY KEY (user_id, game_id, kind)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_user_game_list_user_kind ON user_game_list (user_id, kind)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE user_game_list');
    }
}
