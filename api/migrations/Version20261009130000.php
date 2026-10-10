<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 43.6: who sees that a member is playing. Every existing profile keeps a presence visible to everyone.
 */
final class Version20261009130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Story 43.6: community_profile.presence_visibility';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE community_profile ADD presence_visibility VARCHAR(16) DEFAULT 'everyone' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE community_profile DROP presence_visibility');
    }
}
