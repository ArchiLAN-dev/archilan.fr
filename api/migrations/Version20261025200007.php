<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 45.5: a partner's short description goes up to 250 characters.
 */
final class Version20261025200007 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Story 45.5: partner.description up to 250 characters';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE partner ALTER description TYPE VARCHAR(250)');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE partner ALTER description TYPE VARCHAR(160)');
    }
}
