<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 43.18: a report keeps a copy of content its author can change or take down (a run listing).
 */
final class Version20261010180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Story 43.18: community_content_report.target_snapshot';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE community_content_report ADD target_snapshot JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE community_content_report DROP target_snapshot');
    }
}
