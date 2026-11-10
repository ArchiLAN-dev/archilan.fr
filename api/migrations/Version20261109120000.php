<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 5.10: who registered or confirmed a member on their behalf, when an admin did.
 */
final class Version20261109120000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Story 5.10: registration.created_by_admin_id, registration.confirmed_by_admin_id';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE registration ADD created_by_admin_id VARCHAR(32) DEFAULT NULL');
        $this->addSql('ALTER TABLE registration ADD confirmed_by_admin_id VARCHAR(32) DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE registration DROP created_by_admin_id');
        $this->addSql('ALTER TABLE registration DROP confirmed_by_admin_id');
    }
}
