<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 30.41: opacity of the banner preset laid over a banner image.
 */
final class Version20260930140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Story 30.41: community_profile.banner_overlay';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE community_profile ADD banner_overlay SMALLINT DEFAULT 50 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE community_profile DROP banner_overlay');
    }
}
