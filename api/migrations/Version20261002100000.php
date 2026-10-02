<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 30.45: last time the bridge reported a new check on a slot, for the "En jeu" presence.
 */
final class Version20261002100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Story 30.45: session_slot.last_check_at';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE session_slot ADD last_check_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE session_slot DROP last_check_at');
    }
}
