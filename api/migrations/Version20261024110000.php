<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 41.40: the blind test of an event, and what its finds are worth in event pelles.
 */
final class Version20261024110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Story 41.40: blind_test_setting';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE blind_test_setting (event_id VARCHAR(32) NOT NULL, enabled BOOLEAN NOT NULL, pelles_per_source INT NOT NULL, cap_per_player INT NOT NULL, PRIMARY KEY(event_id))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE blind_test_setting');
    }
}
