<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Community: optional decorative avatar frame on the profile (epic 30). Nullable key (see AvatarFrame);
 * null = no frame. No backfill.
 */
final class Version20260618190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Community: optional avatar_frame on community_profile';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE community_profile ADD COLUMN avatar_frame VARCHAR(32) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE community_profile DROP COLUMN avatar_frame');
    }
}
