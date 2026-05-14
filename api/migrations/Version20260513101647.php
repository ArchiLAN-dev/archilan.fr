<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260513101647 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE games ADD availability_locked BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE personal_run_participants ALTER game_slots TYPE JSON');
        $this->addSql('ALTER TABLE personal_run_participants ALTER game_slots DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE games DROP availability_locked');
        $this->addSql('ALTER TABLE personal_run_participants ALTER game_slots TYPE JSONB');
        $this->addSql('ALTER TABLE personal_run_participants ALTER game_slots SET DEFAULT \'[]\'');
    }
}
