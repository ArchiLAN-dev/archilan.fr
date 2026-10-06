<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 41.25: the welcome quests are for the accounts created from now on - the older members get no back pay.
 */
final class Version20261006180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Story 41.25: wallet_setting welcome_quests_since';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'INSERT INTO wallet_setting (setting_key, value) VALUES (?, ?) ON CONFLICT (setting_key) DO NOTHING',
            ['welcome_quests_since', (new \DateTimeImmutable())->format(\DATE_ATOM)],
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DELETE FROM wallet_setting WHERE setting_key = ?', ['welcome_quests_since']);
    }
}
