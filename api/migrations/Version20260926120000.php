<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 38.9: what the test verdicts of each served apworld have shown over time - last status, last
 * success and its image, failures in a row - so the rolling test confirms a failure before alerting.
 */
final class Version20260926120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Story 38.9: apworld_health';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE apworld_health (
            id VARCHAR(32) NOT NULL,
            game_id VARCHAR(32) NOT NULL,
            apworld_hash VARCHAR(64) NOT NULL,
            last_status VARCHAR(16) DEFAULT NULL,
            last_verdict_at VARCHAR(64) DEFAULT NULL,
            last_success_at VARCHAR(64) DEFAULT NULL,
            last_success_image VARCHAR(255) DEFAULT NULL,
            last_success_image_id VARCHAR(100) DEFAULT NULL,
            consecutive_failures INT NOT NULL,
            PRIMARY KEY (id)
        )');
        $this->addSql('CREATE UNIQUE INDEX uniq_apworld_health_game_hash ON apworld_health (game_id, apworld_hash)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE apworld_health');
    }
}
