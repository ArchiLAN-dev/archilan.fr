<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 16.23: a player of a private run says they are ready.
 */
final class Version20261017100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Story 16.23: run_participant.ready_at';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE run_participant ADD ready_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE run_participant DROP ready_at');
    }
}
