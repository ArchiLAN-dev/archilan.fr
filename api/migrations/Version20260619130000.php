<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Community: structured content reports (story 30.28). Adds category + problem (severity driver) + an
 * optional free-text comment to community_content_report. Existing rows backfill to a sensible category
 * (comment-target → 'comment', else 'other') and problem 'other'.
 */
final class Version20260619130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Community: category/problem/comment on community_content_report';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE community_content_report ADD COLUMN category VARCHAR(32) NOT NULL DEFAULT 'other'");
        $this->addSql("ALTER TABLE community_content_report ADD COLUMN problem VARCHAR(32) NOT NULL DEFAULT 'other'");
        $this->addSql('ALTER TABLE community_content_report ADD COLUMN report_comment VARCHAR(500) DEFAULT NULL');
        // Existing comment-target reports are categorised as such; profile-target reports stay 'other'.
        $this->addSql("UPDATE community_content_report SET category = 'comment' WHERE target_type = 'comment'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE community_content_report DROP COLUMN category');
        $this->addSql('ALTER TABLE community_content_report DROP COLUMN problem');
        $this->addSql('ALTER TABLE community_content_report DROP COLUMN report_comment');
    }
}
