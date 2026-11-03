<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 49.3: where the collection of an event's Minimapperz games stands.
 */
final class Version20261102120002 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Story 49.3: minimapperz_setting collection state';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE minimapperz_setting ADD collected_until TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE minimapperz_setting ADD last_collect_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE minimapperz_setting ADD last_collect_status VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE minimapperz_setting ADD last_collect_games INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE minimapperz_setting ADD last_collect_credited INT DEFAULT 0 NOT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE minimapperz_setting DROP collected_until');
        $this->addSql('ALTER TABLE minimapperz_setting DROP last_collect_at');
        $this->addSql('ALTER TABLE minimapperz_setting DROP last_collect_status');
        $this->addSql('ALTER TABLE minimapperz_setting DROP last_collect_games');
        $this->addSql('ALTER TABLE minimapperz_setting DROP last_collect_credited');
    }
}
