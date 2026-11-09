<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 51.1: event surveys, their questions and the options of a choice question.
 */
final class Version20261109120001 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Story 51.1: survey, survey_question, survey_option';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE survey (id VARCHAR(32) NOT NULL, event_id VARCHAR(32) NOT NULL, title VARCHAR(120) NOT NULL, intro TEXT NOT NULL, audience VARCHAR(16) NOT NULL, anonymous BOOLEAN NOT NULL, send_email BOOLEAN NOT NULL, opens_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, closes_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, status VARCHAR(16) NOT NULL, published_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_survey_event ON survey (event_id)');
        $this->addSql('CREATE TABLE survey_question (id VARCHAR(32) NOT NULL, survey_id VARCHAR(32) NOT NULL, kind VARCHAR(16) NOT NULL, label VARCHAR(200) NOT NULL, required BOOLEAN NOT NULL, position INT NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_survey_question_survey ON survey_question (survey_id, position)');
        $this->addSql('CREATE TABLE survey_option (id VARCHAR(32) NOT NULL, question_id VARCHAR(32) NOT NULL, label VARCHAR(80) NOT NULL, position INT NOT NULL, retired_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_survey_option_question ON survey_option (question_id, position)');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE survey_option');
        $this->addSql('DROP TABLE survey_question');
        $this->addSql('DROP TABLE survey');
    }
}
