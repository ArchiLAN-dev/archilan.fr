<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 51.2: the members' answers to a survey. The participation (who answered) is kept apart from the response
 * (what was answered), so that an anonymous response carries nobody.
 */
final class Version20261109120002 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Story 51.2: survey_participation, survey_response, survey_answer';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE survey_participation (id VARCHAR(32) NOT NULL, survey_id VARCHAR(32) NOT NULL, user_id VARCHAR(32) NOT NULL, responded_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_survey_participation ON survey_participation (survey_id, user_id)');
        $this->addSql('CREATE TABLE survey_response (id VARCHAR(32) NOT NULL, survey_id VARCHAR(32) NOT NULL, user_id VARCHAR(32) DEFAULT NULL, submitted_on DATE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_survey_response_survey ON survey_response (survey_id, user_id)');
        $this->addSql('CREATE TABLE survey_answer (id VARCHAR(32) NOT NULL, response_id VARCHAR(32) NOT NULL, question_id VARCHAR(32) NOT NULL, option_id VARCHAR(32) DEFAULT NULL, text TEXT DEFAULT NULL, rating SMALLINT DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_survey_answer_response ON survey_answer (response_id)');
        $this->addSql('CREATE INDEX idx_survey_answer_option ON survey_answer (option_id)');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE survey_answer');
        $this->addSql('DROP TABLE survey_response');
        $this->addSql('DROP TABLE survey_participation');
    }
}
