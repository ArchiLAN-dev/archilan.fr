<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 30.44: the owner's switch for the holographic name.
 */
final class Version20260930200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Story 30.44: community_profile.holo_name';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE community_profile ADD holo_name BOOLEAN DEFAULT true NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE community_profile DROP holo_name');
    }
}
