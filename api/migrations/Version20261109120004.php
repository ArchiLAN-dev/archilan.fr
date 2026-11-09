<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 52.2: when a member is at the event (arrival, departure). No window: there the whole event.
 */
final class Version20261109120004 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Story 52.2: registration presence windows';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE registration_presence_window (id VARCHAR(32) NOT NULL, registration_id VARCHAR(32) NOT NULL, starts_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, ends_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_registration_presence_window_registration ON registration_presence_window (registration_id, starts_at)');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE registration_presence_window');
    }
}
