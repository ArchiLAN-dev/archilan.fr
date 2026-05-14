<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260513140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add connection fields and session_id to personal_runs for server launch support.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE personal_runs
            ADD COLUMN connection_host VARCHAR(255) DEFAULT NULL,
            ADD COLUMN connection_port INT DEFAULT NULL,
            ADD COLUMN connection_password VARCHAR(120) DEFAULT NULL,
            ADD COLUMN session_id VARCHAR(32) DEFAULT NULL
        ');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE personal_runs
            DROP COLUMN connection_host,
            DROP COLUMN connection_port,
            DROP COLUMN connection_password,
            DROP COLUMN session_id
        ');
    }
}
