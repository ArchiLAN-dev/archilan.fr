<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 45.5: a partner logo drawn for a dark background sits on a dark chip.
 */
final class Version20261025200006 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Story 45.5: partner.logo_on_dark';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE partner ADD logo_on_dark BOOLEAN DEFAULT false NOT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE partner DROP logo_on_dark');
    }
}
