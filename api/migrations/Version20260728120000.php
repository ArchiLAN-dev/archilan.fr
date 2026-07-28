<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Carry the AP item classification flags on persisted feed events (story 32.9), so the timeline can
 * mark progression finds (flags bit 1) apart from filler. Nullable: rows persisted before this
 * migration (or pushed by an older bridge) simply have no flag.
 */
final class Version20260728120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add item_flags to session_feed_event for progression markers (story 32.9)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE session_feed_event ADD item_flags INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE session_feed_event DROP item_flags');
    }
}
