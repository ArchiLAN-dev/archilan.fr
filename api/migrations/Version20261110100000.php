<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Story 52.4: a block survives a server restart - suspended, then resumed when nothing was received in between. */
final class Version20261110100000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Block episodes across a server restart';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE session_slot_block ADD items_received INT DEFAULT NULL');
        $this->addSql('ALTER TABLE session_slot_block ADD suspended_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE session_slot_block ADD resumed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE session_slot_block DROP items_received');
        $this->addSql('ALTER TABLE session_slot_block DROP suspended_at');
        $this->addSql('ALTER TABLE session_slot_block DROP resumed_at');
    }
}
