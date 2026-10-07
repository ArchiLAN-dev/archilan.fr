<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 30.49: when a slot released its items to the others, and when it collected its own back.
 */
final class Version20261007180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Story 30.49: session_slot.released_at, collected_at';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE session_slot ADD released_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE session_slot ADD collected_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE session_slot DROP collected_at');
        $this->addSql('ALTER TABLE session_slot DROP released_at');
    }
}
