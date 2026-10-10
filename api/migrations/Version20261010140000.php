<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 43.14: a draft personal run can be opened to its owner's friends, with an optional number of seats.
 */
final class Version20261010140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Story 43.14: run.openness, run.seats_wanted';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE run ADD openness VARCHAR(16) DEFAULT 'invite' NOT NULL");
        $this->addSql('ALTER TABLE run ADD seats_wanted SMALLINT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE run DROP seats_wanted');
        $this->addSql('ALTER TABLE run DROP openness');
    }
}
