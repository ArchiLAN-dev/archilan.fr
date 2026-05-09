<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260505100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop confirmed_registrations counter column - computed from event_registrations at runtime';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE events DROP COLUMN confirmed_registrations');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE events ADD COLUMN confirmed_registrations INTEGER NOT NULL DEFAULT 0');
    }
}
