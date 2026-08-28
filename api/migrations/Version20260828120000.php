<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 9.52: the values an admin declares for the sub-settings of a dict option.
 *
 * A separate column on purpose. `option_types` is replaced wholesale by `recordOptionTypes()` on
 * every apworld upload and every backfill, so a curation stored there would be erased by the next
 * re-introspection. Keeping it apart also preserves the distinction the whole feature rests on:
 * what the apworld declares, versus what we decided.
 */
final class Version20260828120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add admin-curated dict sub-option values to game (story 9.52)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game ADD dict_option_values JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game DROP dict_option_values');
    }
}
