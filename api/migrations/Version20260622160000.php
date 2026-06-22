<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Community: optional custom image on an achievement definition (story 30.33). A nullable MinIO key, shown
 * in place of the default trophy when set.
 */
final class Version20260622160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Community: custom_image_key on community_achievement_definition (story 30.33)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE community_achievement_definition ADD custom_image_key VARCHAR(512) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE community_achievement_definition DROP custom_image_key');
    }
}
