<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 41.18: each weekly quest has a draw weight (1: as likely as any other).
 */
final class Version20261006100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Story 41.18: quest_definition.draw_weight';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE quest_definition ADD draw_weight INT DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE quest_definition DROP draw_weight');
    }
}
