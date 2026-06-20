<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Community: member-uploaded custom avatar (story 30.27). Nullable MinIO object key; when set it takes
 * precedence over the resolved external avatar_url and is presigned at read. No backfill.
 */
final class Version20260619120001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Community: custom_avatar_key on community_profile';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE community_profile ADD COLUMN custom_avatar_key VARCHAR(512) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE community_profile DROP COLUMN custom_avatar_key');
    }
}
