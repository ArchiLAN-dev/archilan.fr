<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 41.36: bronze pelles belong to one private run.
 */
final class Version20261018100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Story 41.36: pelle_movement.run_id';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pelle_movement ADD run_id VARCHAR(32) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_pelle_movement_run ON pelle_movement (run_id, user_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_pelle_movement_run');
        $this->addSql('ALTER TABLE pelle_movement DROP run_id');
    }
}
