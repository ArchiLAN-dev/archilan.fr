<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 38.14: an apworld candidate held for the admin's approval, and who approved it.
 */
final class Version20261002140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Story 38.14: apworld_candidate.hold_for_approval, approved_by';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE apworld_candidate ADD hold_for_approval BOOLEAN DEFAULT false NOT NULL, ADD approved_by VARCHAR(32) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE apworld_candidate DROP hold_for_approval, DROP approved_by');
    }
}
