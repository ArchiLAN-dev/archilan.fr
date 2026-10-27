<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 32.25: the probable victims of a DeathLink death kept in the game feed.
 */
final class Version20261025200001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Story 32.25: session_feed_event.victim_names';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE session_feed_event ADD victim_names JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE session_feed_event DROP victim_names');
    }
}
