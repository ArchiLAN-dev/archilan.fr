<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 16.18: a private run can host a seed generated somewhere else.
 *
 * The archive lives in object storage and its slot table on the run, not on the session: a relaunch
 * throws the session away and rebuilds it, and the assignment of slots to participants has to
 * survive that.
 */
final class Version20260826090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add imported seed columns to run (story 16.18)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE run ADD imported_output_key VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE run ADD imported_slots JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE run DROP imported_output_key');
        $this->addSql('ALTER TABLE run DROP imported_slots');
    }
}
