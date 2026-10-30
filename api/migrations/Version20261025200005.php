<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 45.3: the partners thanked on an event, by reference.
 */
final class Version20261025200005 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Story 45.3: event.partners';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event ADD partners JSON DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event DROP partners');
    }
}
