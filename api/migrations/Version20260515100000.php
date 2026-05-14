<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260515100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add game_slots JSON column to personal_run_participants for per-participant game selection';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE personal_run_participants ADD game_slots JSONB NOT NULL DEFAULT '[]'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE personal_run_participants DROP COLUMN game_slots');
    }
}
