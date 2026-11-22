<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 16.27: a participant the owner of a private run made co-organiser.
 */
final class Version20261122100002 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Schema: run_participant.co_owner_since';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE run_participant ADD co_owner_since TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE run_participant DROP co_owner_since');
    }
}
