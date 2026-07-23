<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260722100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Games: add archipelago_description TEXT (public Archipelago-side description, story 3.13)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game ADD COLUMN archipelago_description TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game DROP COLUMN archipelago_description');
    }
}
