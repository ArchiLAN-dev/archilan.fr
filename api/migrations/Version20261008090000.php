<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 30.52: the collections of achievements, the collection an achievement belongs to, and who completed which.
 */
final class Version20261008090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Story 30.52: community_achievement_collection, its completions, community_achievement_definition.collection_id';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE community_achievement_collection (id VARCHAR(32) NOT NULL, name VARCHAR(120) NOT NULL, description TEXT NOT NULL, position INT NOT NULL, secret BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, image_key VARCHAR(512) DEFAULT NULL, cosmetic_type VARCHAR(12) DEFAULT NULL, cosmetic_key VARCHAR(64) DEFAULT NULL, reward_pelles INT DEFAULT 0 NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE community_achievement_collection_completion (id VARCHAR(32) NOT NULL, user_id VARCHAR(32) NOT NULL, collection_id VARCHAR(32) NOT NULL, completed_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_community_collection_completion ON community_achievement_collection_completion (user_id, collection_id)');
        $this->addSql('ALTER TABLE community_achievement_definition ADD collection_id VARCHAR(32) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE community_achievement_definition DROP collection_id');
        $this->addSql('DROP TABLE community_achievement_collection_completion');
        $this->addSql('DROP TABLE community_achievement_collection');
    }
}
