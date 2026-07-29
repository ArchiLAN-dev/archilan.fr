<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260726100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Games: add disabled_at + disabled_message (temporary admin kill switch, story 11.4)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game ADD COLUMN disabled_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE game ADD COLUMN disabled_message VARCHAR(500) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game DROP COLUMN disabled_at');
        $this->addSql('ALTER TABLE game DROP COLUMN disabled_message');
    }
}
