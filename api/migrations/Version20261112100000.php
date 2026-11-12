<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** v0.66.1: the publishing session of a local stream, to cut one started with a replaced key. */
final class Version20261112100000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Local broadcasting: publishing session and its key version';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE streaming_local_stream ADD session_id VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE streaming_local_stream ADD session_key_version INT DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE streaming_local_stream DROP session_key_version');
        $this->addSql('ALTER TABLE streaming_local_stream DROP session_id');
    }
}
