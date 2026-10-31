<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 3.15: the event the admin features on the home page.
 */
final class Version20261031100000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Story 3.15: event.featured_on_home';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event ADD featured_on_home BOOLEAN DEFAULT false NOT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event DROP featured_on_home');
    }
}
