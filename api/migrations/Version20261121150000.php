<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Hotfix v1.0.3: the members' counts on the week's quest objectives, kept by the worker every five minutes, so a
 * member's progress is read instead of counted from the week's feed during the request (past 10 s on the week of a
 * large event).
 */
final class Version20261121150000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Schema: quest_progress_count, quest_progress_run';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE quest_progress_count (week_key VARCHAR(8) NOT NULL, objective_key VARCHAR(120) NOT NULL, user_id VARCHAR(36) NOT NULL, count INT NOT NULL, PRIMARY KEY (week_key, objective_key, user_id))');
        $this->addSql('CREATE INDEX idx_quest_progress_count_member ON quest_progress_count (week_key, user_id)');
        $this->addSql('CREATE TABLE quest_progress_run (week_key VARCHAR(8) NOT NULL, counted_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (week_key))');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE quest_progress_run');
        $this->addSql('DROP TABLE quest_progress_count');
    }
}
