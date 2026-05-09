<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260504150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop game_options column from registrations (story 12-1)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE registrations_registrations DROP COLUMN IF EXISTS game_options');
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE registrations_registrations ADD COLUMN game_options JSONB NOT NULL DEFAULT '{}'");
    }
}
