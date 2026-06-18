<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260618110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Community: owner-arranged showcase layout on community_profile (epic 30, story 30.6)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE community_profile ADD COLUMN showcase_layout JSON NOT NULL DEFAULT '[]'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE community_profile DROP COLUMN showcase_layout');
    }
}
