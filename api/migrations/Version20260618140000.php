<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260618140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Community: profile comments + content reports (epic 30, story 30.10)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE community_profile_comment (id VARCHAR(32) NOT NULL, profile_user_id VARCHAR(32) NOT NULL, author_id VARCHAR(32) NOT NULL, body TEXT NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, hidden_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_community_comment_profile ON community_profile_comment (profile_user_id, created_at)');
        $this->addSql('CREATE INDEX idx_community_comment_author ON community_profile_comment (author_id, created_at)');

        $this->addSql('CREATE TABLE community_content_report (id VARCHAR(32) NOT NULL, reporter_id VARCHAR(32) NOT NULL, target_type VARCHAR(16) NOT NULL, target_id VARCHAR(32) NOT NULL, reason VARCHAR(500) NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, resolved_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, resolved_by VARCHAR(32) DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_community_report ON community_content_report (reporter_id, target_type, target_id)');
        $this->addSql('CREATE INDEX idx_community_report_resolved ON community_content_report (resolved_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE community_profile_comment');
        $this->addSql('DROP TABLE community_content_report');
    }
}
