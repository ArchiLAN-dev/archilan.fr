<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260611100001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add session.generated_output_key (MinIO key of the generated output archive, for owner/admin spoiler download - story 16.8)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE session ADD COLUMN generated_output_key VARCHAR(500) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE session DROP COLUMN generated_output_key');
    }
}
