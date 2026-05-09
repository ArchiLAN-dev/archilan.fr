<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260505180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add bridge_port column to archipelago_sessions (Story 9.6)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE archipelago_sessions ADD bridge_port INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE archipelago_sessions DROP COLUMN bridge_port');
    }
}
