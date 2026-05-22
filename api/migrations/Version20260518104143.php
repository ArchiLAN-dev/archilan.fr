<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260518104143 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add generated_seed_path to weekly_runs for pre-generated Archipelago worlds';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE weekly_runs ADD COLUMN generated_seed_path TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE weekly_runs DROP COLUMN generated_seed_path');
    }
}
