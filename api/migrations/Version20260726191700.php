<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Personal-run recap visibility flag (story 32.5).
 *
 * A finished personal run's recap is private (owner + participants) by default; the owner can publish
 * it to make it publicly shareable. Existing runs default to private.
 */
final class Version20260726191700 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add run.recap_public for personal-run recap publish toggle (story 32.5)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE run ADD recap_public BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE run DROP recap_public');
    }
}
