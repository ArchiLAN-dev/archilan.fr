<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 30.44: the holographic name became a titled name (title above the name, rarity colours).
 */
final class Version20260930210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Story 30.44: community_profile.holo_name renamed titled_name';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE community_profile RENAME COLUMN holo_name TO titled_name');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE community_profile RENAME COLUMN titled_name TO holo_name');
    }
}
