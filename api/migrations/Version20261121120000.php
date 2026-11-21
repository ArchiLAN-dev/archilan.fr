<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Hotfix v1.0.1: the weekly quests read the checks of a week across every session (`type` and `occurred_at`), and the
 * feed had no index for it - `/me/quests` scanned the whole table and went past 30 s. Built CONCURRENTLY, outside a
 * transaction, so the feed of running sessions keeps writing while it builds.
 */
final class Version20261121120000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Schema: index session_feed_event (type, occurred_at)';
    }

    #[\Override]
    public function isTransactional(): bool
    {
        return false;
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_feed_event_type_time ON session_feed_event (type, occurred_at)');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS idx_feed_event_type_time');
    }
}
