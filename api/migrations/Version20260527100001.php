<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260527100001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add admin_password to session for orchestrateur pre-launch credential storage';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE session ADD COLUMN admin_password VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE session DROP COLUMN admin_password');
    }
}
