<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260615120001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'User: add steam_profile (saved Steam reference for library coupling) (story 28.3)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD COLUMN steam_profile VARCHAR(190) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP COLUMN steam_profile');
    }
}
