<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 47.1: the member lets the ArchiLAN broadcast show their Twitch stream during the event.
 */
final class Version20261031110000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Story 47.1: registration.broadcast_consent';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE registration ADD broadcast_consent BOOLEAN DEFAULT true NOT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE registration DROP broadcast_consent');
    }
}
