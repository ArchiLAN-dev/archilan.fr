<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Story 54.8: the last try of each variant of a pack version, by an admin, on the site's tracker. */
final class Version20261117120000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Trackers: compatibility report per pack version and variant';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE tracker_pack_compatibility (version_id VARCHAR(32) NOT NULL, variant_uid VARCHAR(120) NOT NULL, status VARCHAR(16) NOT NULL, errors JSON NOT NULL, unsupported_apis JSON NOT NULL, engine_version INT NOT NULL, reported_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, reported_by VARCHAR(64) NOT NULL, PRIMARY KEY (version_id, variant_uid))');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE tracker_pack_compatibility');
    }
}
