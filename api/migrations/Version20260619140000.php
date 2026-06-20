<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Identity: account moderation state (story 30.29). A suspension (auto-expiring) or a permanent ban blocks
 * access; moderation_reason records why. No backfill — every existing account stays active.
 */
final class Version20260619140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Identity: suspended_until / banned_at / moderation_reason on user';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD COLUMN suspended_until TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD COLUMN banned_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD COLUMN moderation_reason VARCHAR(500) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP COLUMN suspended_until');
        $this->addSql('ALTER TABLE "user" DROP COLUMN banned_at');
        $this->addSql('ALTER TABLE "user" DROP COLUMN moderation_reason');
    }
}
