<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 4.23: when a member was reminded to confirm their registration, so the reminder goes once.
 */
final class Version20261012110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Story 4.23: registration.reminder_sent_at';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE registration ADD reminder_sent_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE registration DROP reminder_sent_at');
    }
}
