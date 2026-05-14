<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260512223157 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE games DROP adult_content');
        $this->addSql('ALTER TABLE games DROP bundled_with_ap');
        $this->addSql('ALTER TABLE games DROP availability_locked');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE games ADD adult_content BOOLEAN NOT NULL');
        $this->addSql('ALTER TABLE games ADD bundled_with_ap BOOLEAN NOT NULL');
        $this->addSql('ALTER TABLE games ADD availability_locked BOOLEAN NOT NULL');
    }
}
