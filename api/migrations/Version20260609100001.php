<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260609100001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create session_config_profiles and session_config_overrides (epic 27 - per-type config + per-session override)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE session_config_profiles (
                session_type VARCHAR(16) NOT NULL,
                config JSON NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                PRIMARY KEY(session_type)
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE session_config_overrides (
                session_id VARCHAR(191) NOT NULL,
                override_config JSON NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                PRIMARY KEY(session_id)
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE session_config_overrides');
        $this->addSql('DROP TABLE session_config_profiles');
    }
}
