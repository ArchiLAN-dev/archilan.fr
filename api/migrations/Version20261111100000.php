<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Story 53.4: the practical information of an event, for its registrants only. */
final class Version20261111100000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Registrants-only practical information on an event';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event ADD practical_info TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE event ADD practical_info_updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event DROP practical_info');
        $this->addSql('ALTER TABLE event DROP practical_info_updated_at');
    }
}
