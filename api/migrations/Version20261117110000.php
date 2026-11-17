<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Story 54.3: the update a pack's check found, and how the last check went. */
final class Version20261117110000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Trackers: available update, last check date and error';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tracker_pack ADD available_update JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE tracker_pack ADD last_checked_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE tracker_pack ADD last_check_error VARCHAR(64) DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tracker_pack DROP last_check_error');
        $this->addSql('ALTER TABLE tracker_pack DROP last_checked_at');
        $this->addSql('ALTER TABLE tracker_pack DROP available_update');
    }
}
