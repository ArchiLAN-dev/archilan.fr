<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Identity: self-service profile-URL (slug) change (story 2.10). `previous_slug` + `slug_changed_at`
 * drive the 30-day cooldown and the "released slug reserved for its former owner" rule.
 */
final class Version20260622160001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Identity: previous_slug + slug_changed_at on user (story 2.10 - choose profile URL)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD previous_slug VARCHAR(80) DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD slug_changed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP previous_slug');
        $this->addSql('ALTER TABLE "user" DROP slug_changed_at');
    }
}
