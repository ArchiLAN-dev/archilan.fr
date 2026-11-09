<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Story 52.5: the names on a Passeur opening, and when its absent player closed the notice listing it. */
final class Version20261109120006 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Passeur opening: buyer and absent names, seen by the absent player';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE passeur_opening ADD buyer_name VARCHAR(100) DEFAULT '' NOT NULL");
        $this->addSql("ALTER TABLE passeur_opening ADD absent_name VARCHAR(100) DEFAULT '' NOT NULL");
        $this->addSql('ALTER TABLE passeur_opening ADD absent_seen_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_passeur_opening_absent ON passeur_opening (absent_user_id, session_id)');
        $this->addSql('CREATE INDEX idx_passeur_opening_event ON passeur_opening (event_id)');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_passeur_opening_event');
        $this->addSql('DROP INDEX idx_passeur_opening_absent');
        $this->addSql('ALTER TABLE passeur_opening DROP absent_seen_at');
        $this->addSql('ALTER TABLE passeur_opening DROP absent_name');
        $this->addSql('ALTER TABLE passeur_opening DROP buyer_name');
    }
}
