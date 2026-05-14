<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260511110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add apworld_release_url to games table (story 14.6)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE games ADD apworld_release_url VARCHAR(500) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE games DROP apworld_release_url');
    }
}
