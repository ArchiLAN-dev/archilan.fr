<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 51.3: when a survey's audience was told it opened, when the automatic reminder went out, and the last
 * reminder of any kind (two reminders are at least 24 hours apart).
 */
final class Version20261109120003 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Story 51.3: survey opening notice and reminders';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE survey ADD opening_notified_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE survey ADD reminder_sent_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE survey ADD last_reminded_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE survey DROP opening_notified_at');
        $this->addSql('ALTER TABLE survey DROP reminder_sent_at');
        $this->addSql('ALTER TABLE survey DROP last_reminded_at');
    }
}
