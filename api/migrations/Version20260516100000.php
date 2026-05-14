<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260516100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create game_requests table for community game support requests';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE game_requests (
            id VARCHAR(32) NOT NULL,
            game_name VARCHAR(255) NOT NULL,
            normalized_name VARCHAR(255) NOT NULL,
            user_id VARCHAR(32) NOT NULL,
            created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            PRIMARY KEY (id)
        )');
        $this->addSql('CREATE UNIQUE INDEX uq_game_requests_user_game ON game_requests (user_id, normalized_name)');
        $this->addSql('CREATE INDEX idx_game_requests_normalized ON game_requests (normalized_name)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE game_requests');
    }
}
