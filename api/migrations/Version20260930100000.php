<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 30.40: banner image, and the first frame of a GIF avatar or banner.
 */
final class Version20260930100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Story 30.40: community_profile custom banner and still keys';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE community_profile ADD custom_avatar_still_key VARCHAR(512) DEFAULT NULL');
        $this->addSql('ALTER TABLE community_profile ADD custom_banner_key VARCHAR(512) DEFAULT NULL');
        $this->addSql('ALTER TABLE community_profile ADD custom_banner_still_key VARCHAR(512) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE community_profile DROP custom_avatar_still_key');
        $this->addSql('ALTER TABLE community_profile DROP custom_banner_key');
        $this->addSql('ALTER TABLE community_profile DROP custom_banner_still_key');
    }
}
