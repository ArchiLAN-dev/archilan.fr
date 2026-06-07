<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260602100002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename weekly_runs.generated_seed_path to generated_output_key (now a MinIO output key, not a path)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE weekly_runs RENAME COLUMN generated_seed_path TO generated_output_key');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE weekly_runs RENAME COLUMN generated_output_key TO generated_seed_path');
    }
}
