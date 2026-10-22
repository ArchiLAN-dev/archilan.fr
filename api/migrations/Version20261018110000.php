<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 41.38: a bounty of a private run holds the run's bronze.
 */
final class Version20261018110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Story 41.38: item_bounty.run_id';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE item_bounty ADD run_id VARCHAR(32) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE item_bounty DROP run_id');
    }
}
