<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 40.6: whether a type of notification also goes to the member's Discord direct messages (null = default).
 */
final class Version20261011120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Story 40.6: community_notification_preference.discord';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE community_notification_preference ADD discord BOOLEAN DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE community_notification_preference DROP discord');
    }
}
