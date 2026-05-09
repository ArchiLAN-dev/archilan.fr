<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260505120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop type column from events table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE events DROP COLUMN type');
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE events ADD COLUMN type VARCHAR(60) NOT NULL DEFAULT ''");
    }
}
