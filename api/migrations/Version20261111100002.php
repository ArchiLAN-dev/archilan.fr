<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Story 53.8: the mail of the day before, switchable per event and sent once per registration. */
final class Version20261111100002 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Mail of the day before an event: per-event switch and per-registration sent mark';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event ADD eve_reminder_enabled BOOLEAN DEFAULT true NOT NULL');
        $this->addSql('ALTER TABLE registration ADD eve_reminder_sent_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event DROP eve_reminder_enabled');
        $this->addSql('ALTER TABLE registration DROP eve_reminder_sent_at');
    }
}
