<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 43.17: a draft personal run listed for every member, with a message, an optional date, and its age.
 */
final class Version20261010170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Story 43.17: run.pitch, run.planned_for, run.listed_at, run.last_arrival_at';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE run ADD pitch VARCHAR(280) DEFAULT NULL');
        $this->addSql('ALTER TABLE run ADD planned_for TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE run ADD listed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE run ADD last_arrival_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE run DROP last_arrival_at');
        $this->addSql('ALTER TABLE run DROP listed_at');
        $this->addSql('ALTER TABLE run DROP planned_for');
        $this->addSql('ALTER TABLE run DROP pitch');
    }
}
