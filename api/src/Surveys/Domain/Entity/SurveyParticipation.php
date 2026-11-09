<?php

declare(strict_types=1);

namespace App\Surveys\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * That a member answered a survey, and when (story 51.2). Always by name, even for an anonymous survey: it is what
 * keeps a member from answering twice and what tells who to remind (story 51.3). It says nothing of the answers.
 */
#[ORM\Entity]
#[ORM\Table(name: 'survey_participation')]
#[ORM\UniqueConstraint(name: 'uniq_survey_participation', columns: ['survey_id', 'user_id'])]
final class SurveyParticipation
{
    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 32)]
        private string $id,
        #[ORM\Column(name: 'survey_id', type: 'string', length: 32)]
        private string $surveyId,
        #[ORM\Column(name: 'user_id', type: 'string', length: 32)]
        private string $userId,
        #[ORM\Column(name: 'responded_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $respondedAt,
    ) {
    }

    public static function record(string $id, string $surveyId, string $userId, \DateTimeImmutable $now): self
    {
        return new self($id, $surveyId, $userId, $now);
    }

    /** A nominative answer replaced later is a new answer. */
    public function renew(\DateTimeImmutable $now): void
    {
        $this->respondedAt = $now;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getSurveyId(): string
    {
        return $this->surveyId;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function getRespondedAt(): \DateTimeImmutable
    {
        return $this->respondedAt;
    }
}
