<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260611100002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Backfill: strip leading UTF-8 BOM from existing game.default_yaml (apworld templates authored with a BOM broke YAML parsing -> empty game -> failed generation)';
    }

    public function up(Schema $schema): void
    {
        // The BOM (U+FEFF) is a single leading character; strip it where present.
        $this->addSql("UPDATE game SET default_yaml = right(default_yaml, length(default_yaml) - 1) WHERE default_yaml IS NOT NULL AND left(default_yaml, 1) = E'\\uFEFF'");
    }

    public function down(Schema $schema): void
    {
        // Irreversible data cleanup: the stripped BOM was a defect and is not restored.
    }
}
