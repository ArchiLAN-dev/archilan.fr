<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260519120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add performance indexes on archipelago_session_slots';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_session_slots_released_goal ON archipelago_session_slots (was_released, goal_reached_at)');
        $this->addSql('CREATE INDEX idx_session_slots_session_id ON archipelago_session_slots (session_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_session_slots_released_goal');
        $this->addSql('DROP INDEX idx_session_slots_session_id');
    }
}
