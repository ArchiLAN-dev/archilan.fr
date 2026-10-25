<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 41.54: whether members not registered to an event earn pelles at its blind test (on by default).
 */
final class Version20261025200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Story 41.54: blind_test_setting.pelles_for_unregistered';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE blind_test_setting ADD pelles_for_unregistered BOOLEAN DEFAULT true NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE blind_test_setting DROP pelles_for_unregistered');
    }
}
