<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Hotfix v0.70.2: Archipelago item and location ids pass 2^31 in some games (922026936107125, 6242624027), so a feed
 * event of those games failed to persist - and closed the entity manager for the rest of the push.
 */
final class Version20261118180000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Schema: session_feed_event item_id and location_id as BIGINT';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE session_feed_event ALTER item_id TYPE BIGINT');
        $this->addSql('ALTER TABLE session_feed_event ALTER location_id TYPE BIGINT');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE session_feed_event ALTER item_id TYPE INT');
        $this->addSql('ALTER TABLE session_feed_event ALTER location_id TYPE INT');
    }
}
