<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Sessions: `session_recap` projection backing the public party recap (story 32.1).
 *
 * One row per finished session, built once at archival by parsing the generation
 * spoiler and rebuilt idempotently (the session id is the primary key). Holds the
 * item-exchange graph and the named superlatives, all in slot-id space so the
 * public read joins it to the podium by slot id.
 */
final class Version20260622160002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Sessions: session_recap projection table (story 32.1 - public party recap)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE session_recap (session_id VARCHAR(36) NOT NULL, generated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, nodes JSON NOT NULL, edges JSON NOT NULL, local_items JSON NOT NULL, superlatives JSON NOT NULL, PRIMARY KEY (session_id))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE session_recap');
    }
}
