<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 49.2: the Minimapperz codes of an event, each handed to at most one registrant.
 */
final class Version20261102120001 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Story 49.2: minimapperz_code';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE minimapperz_code (event_id VARCHAR(32) NOT NULL, code VARCHAR(100) NOT NULL, imported_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, user_id VARCHAR(32) DEFAULT NULL, assigned_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, PRIMARY KEY(event_id, code))');
        $this->addSql('CREATE UNIQUE INDEX uniq_minimapperz_code_member ON minimapperz_code (event_id, user_id)');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE minimapperz_code');
    }
}
