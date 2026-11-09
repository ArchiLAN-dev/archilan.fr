<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 52.4: the calls to the Passeur. The partial unique index locks a chest while it is being opened.
 */
final class Version20261109120005 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Story 52.4 - passeur_opening';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE passeur_opening (id VARCHAR(32) NOT NULL, session_id VARCHAR(64) NOT NULL, request_key VARCHAR(120) NOT NULL, buyer_user_id VARCHAR(32) NOT NULL, buyer_slot INT NOT NULL, finding_slot INT NOT NULL, absent_user_id VARCHAR(32) DEFAULT NULL, location_id BIGINT NOT NULL, location_name VARCHAR(200) NOT NULL, item_name VARCHAR(200) NOT NULL, kind VARCHAR(10) NOT NULL, event_id VARCHAR(32) DEFAULT NULL, run_id VARCHAR(32) DEFAULT NULL, price INT NOT NULL, status VARCHAR(10) NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, share INT NOT NULL, failure_code VARCHAR(40) DEFAULT NULL, settled_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_passeur_opening_request ON passeur_opening (request_key)');
        $this->addSql("CREATE UNIQUE INDEX uniq_passeur_opening_chest ON passeur_opening (session_id, finding_slot, location_id) WHERE status <> 'failed'");
        $this->addSql('CREATE INDEX idx_passeur_opening_world ON passeur_opening (session_id, finding_slot, created_at)');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE passeur_opening');
    }
}
