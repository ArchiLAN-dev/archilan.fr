<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 41.45: the one-time codes that tell the blind test app who a member is.
 */
final class Version20261024120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Story 41.45: blind_test_login_code';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE blind_test_login_code (code_hash VARCHAR(64) NOT NULL, user_id VARCHAR(32) NOT NULL, event_id VARCHAR(32) NOT NULL, state VARCHAR(64) NOT NULL, expires_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY(code_hash))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE blind_test_login_code');
    }
}
