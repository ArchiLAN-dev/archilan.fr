<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 38.22: the games a member follows, to be told of their new apworlds.
 */
final class Version20261024100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Story 38.22: game_follow';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE game_follow (id VARCHAR(32) NOT NULL, user_id VARCHAR(32) NOT NULL, game_id VARCHAR(32) NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_game_follow ON game_follow (user_id, game_id)');
        $this->addSql('CREATE INDEX idx_game_follow_game ON game_follow (game_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE game_follow');
    }
}
