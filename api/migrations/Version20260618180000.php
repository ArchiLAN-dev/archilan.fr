<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Community: optional display-name override on the profile (epic 30). When set, it is shown on the public
 * profile instead of the account name; null falls back to the Identity display name. Nullable, no backfill.
 */
final class Version20260618180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Community: optional display_name override on community_profile';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE community_profile ADD COLUMN display_name VARCHAR(80) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE community_profile DROP COLUMN display_name');
    }
}
