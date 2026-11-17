<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Story 54.2: whether a pack is offered to players, which one opens by default, and the vouched licence. */
final class Version20261117100000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Trackers: enabled, default pack and variant, licence acknowledgement';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tracker_pack ADD enabled BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE tracker_pack ADD is_default BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE tracker_pack ADD default_variant_uid VARCHAR(120) DEFAULT NULL');
        $this->addSql('ALTER TABLE tracker_pack ADD license_acknowledged BOOLEAN DEFAULT false NOT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tracker_pack DROP license_acknowledged');
        $this->addSql('ALTER TABLE tracker_pack DROP default_variant_uid');
        $this->addSql('ALTER TABLE tracker_pack DROP is_default');
        $this->addSql('ALTER TABLE tracker_pack DROP enabled');
    }
}
