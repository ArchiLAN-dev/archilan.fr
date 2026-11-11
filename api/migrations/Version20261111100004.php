<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Story 47.6: the streams the régie agent reports on the LAN media server of an event. */
final class Version20261111100004 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Local broadcasting: régie agent reports and stream state';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE streaming_local_media_server (event_id VARCHAR(32) NOT NULL, reported_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, live_count INT NOT NULL, PRIMARY KEY (event_id))');
        $this->addSql('CREATE TABLE streaming_local_stream (id VARCHAR(32) NOT NULL, event_id VARCHAR(32) NOT NULL, registration_id VARCHAR(32) NOT NULL, path VARCHAR(64) NOT NULL, online_since TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, last_seen_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_streaming_local_stream_registration ON streaming_local_stream (event_id, registration_id)');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE streaming_local_stream');
        $this->addSql('DROP TABLE streaming_local_media_server');
    }
}
