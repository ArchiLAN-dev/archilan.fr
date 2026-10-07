<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 41.27: how a profile title shines, and its icon.
 */
final class Version20261007120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Story 41.27: profile_title.rarity, profile_title.icon';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE profile_title ADD rarity VARCHAR(10) DEFAULT 'common' NOT NULL");
        $this->addSql('ALTER TABLE profile_title ADD icon VARCHAR(10) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE profile_title DROP icon');
        $this->addSql('ALTER TABLE profile_title DROP rarity');
    }
}
