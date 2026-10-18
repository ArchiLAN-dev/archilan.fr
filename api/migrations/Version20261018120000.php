<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 41.38: paid in bronze, hints and bounties are on by default in private runs. The stored "private" profile
 * wins over the code's default, so it is switched too; a run that overrode either keeps its own choice.
 */
final class Version20261018120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Story 41.38: pelle hints and bounties on in the private profile';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE session_config_profiles
               SET config = jsonb_set(jsonb_set(config::jsonb, '{server,pelleHints}', 'true'), '{server,pelleBounties}', 'true')::json
             WHERE session_type = 'private'
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE session_config_profiles
               SET config = jsonb_set(jsonb_set(config::jsonb, '{server,pelleHints}', 'false'), '{server,pelleBounties}', 'false')::json
             WHERE session_type = 'private'
            SQL);
    }
}
