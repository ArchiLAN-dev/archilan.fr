<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260617200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'PersonalRuns: yaml_template - named reusable per-game YAML configs (story 16.11)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE yaml_template (id VARCHAR(32) NOT NULL, user_id VARCHAR(32) NOT NULL, game_id VARCHAR(32) NOT NULL, name VARCHAR(80) NOT NULL, yaml TEXT NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_yaml_template_user_game_name ON yaml_template (user_id, game_id, name)');
        $this->addSql('CREATE INDEX idx_yaml_template_user_game ON yaml_template (user_id, game_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE yaml_template');
    }
}
