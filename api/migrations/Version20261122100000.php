<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 56.1: the size of each slot's world and the progression it carries, read from the generation's multidata.
 * Nullable: a session generated before stays unmeasured until `app:sessions:measure-slots`.
 */
final class Version20261122100000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Schema: session_slot.locations_total, own_progression, hosted_progression';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE session_slot ADD locations_total INT DEFAULT NULL');
        $this->addSql('ALTER TABLE session_slot ADD own_progression INT DEFAULT NULL');
        $this->addSql('ALTER TABLE session_slot ADD hosted_progression INT DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE session_slot DROP locations_total');
        $this->addSql('ALTER TABLE session_slot DROP own_progression');
        $this->addSql('ALTER TABLE session_slot DROP hosted_progression');
    }
}
