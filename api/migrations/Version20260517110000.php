<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260517110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add was_released flag to archipelago_session_slots for invalidation tracking';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE archipelago_session_slots ADD was_released BOOLEAN NOT NULL DEFAULT FALSE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE archipelago_session_slots DROP COLUMN was_released');
    }
}
