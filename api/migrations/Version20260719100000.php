<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260719100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Games: add location_names JSON (static apworld location list from introspection, story 4.14)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game ADD COLUMN location_names JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game DROP COLUMN location_names');
    }
}
