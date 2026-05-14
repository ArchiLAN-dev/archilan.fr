<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260515110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add restart_failed flag to archipelago_sessions';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE archipelago_sessions ADD restart_failed BOOLEAN NOT NULL DEFAULT FALSE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE archipelago_sessions DROP COLUMN restart_failed');
    }
}
