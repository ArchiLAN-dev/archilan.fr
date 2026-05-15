<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260514131439 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX idx_session_slots_session_id');
        $this->addSql('DROP INDEX idx_session_slots_released_goal');
        $this->addSql('ALTER TABLE archipelago_sessions ADD restart_failed BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE identity_users ALTER slug DROP NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE INDEX idx_session_slots_session_id ON archipelago_session_slots (session_id)');
        $this->addSql('CREATE INDEX idx_session_slots_released_goal ON archipelago_session_slots (was_released, goal_reached_at)');
        $this->addSql('ALTER TABLE archipelago_sessions DROP restart_failed');
        $this->addSql('ALTER TABLE identity_users ALTER slug SET NOT NULL');
    }
}
