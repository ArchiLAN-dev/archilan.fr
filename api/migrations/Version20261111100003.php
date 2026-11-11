<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Story 47.5: the LAN media server of an event and the version of each registrant's publishing key. */
final class Version20261111100003 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Local broadcasting: media server host per event, media key version per registration';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event ADD media_server_host VARCHAR(253) DEFAULT NULL');
        $this->addSql('ALTER TABLE registration ADD media_key_version INT DEFAULT 0 NOT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event DROP media_server_host');
        $this->addSql('ALTER TABLE registration DROP media_key_version');
    }
}
