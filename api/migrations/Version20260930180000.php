<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 30.43: framing (point aimed at + zoom) of the uploaded avatar and banner image.
 */
final class Version20260930180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Story 30.43: community_profile avatar/banner framing';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE community_profile ADD avatar_framing_x SMALLINT DEFAULT 50 NOT NULL, ADD avatar_framing_y SMALLINT DEFAULT 50 NOT NULL, ADD avatar_framing_zoom SMALLINT DEFAULT 100 NOT NULL, ADD banner_framing_x SMALLINT DEFAULT 50 NOT NULL, ADD banner_framing_y SMALLINT DEFAULT 50 NOT NULL, ADD banner_framing_zoom SMALLINT DEFAULT 100 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE community_profile DROP avatar_framing_x, DROP avatar_framing_y, DROP avatar_framing_zoom, DROP banner_framing_x, DROP banner_framing_y, DROP banner_framing_zoom');
    }
}
