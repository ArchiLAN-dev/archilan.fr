<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 41.23: the colour a member bought for their name.
 */
final class Version20261006160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Story 41.23: community_profile.name_color';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE community_profile ADD name_color VARCHAR(16) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE community_profile DROP name_color');
    }
}
