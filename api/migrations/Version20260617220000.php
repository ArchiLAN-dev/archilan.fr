<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260617220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Community: cache resolved avatar URL on community_profile (epic 30, story 30.2)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE community_profile ADD COLUMN avatar_url VARCHAR(512) DEFAULT NULL');
        $this->addSql('ALTER TABLE community_profile ADD COLUMN avatar_resolved_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE community_profile DROP COLUMN avatar_url');
        $this->addSql('ALTER TABLE community_profile DROP COLUMN avatar_resolved_at');
    }
}
