<?php

declare(strict_types=1);

namespace App\Surveys\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * One member's answers to a survey (story 51.2). A nominative response carries its author and is replaced until the
 * survey closes. An anonymous one carries nobody and only its day, with an id that does not sort in time: nothing
 * ties it back to the participation written alongside.
 */
#[ORM\Entity]
#[ORM\Table(name: 'survey_response')]
#[ORM\Index(name: 'idx_survey_response_survey', columns: ['survey_id', 'user_id'])]
final class SurveyResponse
{
    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 32)]
        private string $id,
        #[ORM\Column(name: 'survey_id', type: 'string', length: 32)]
        private string $surveyId,
        #[ORM\Column(name: 'user_id', type: 'string', length: 32, nullable: true)]
        private ?string $userId,
        #[ORM\Column(name: 'submitted_on', type: 'date_immutable')]
        private \DateTimeImmutable $submittedOn,
    ) {
    }

    public static function nominative(string $id, string $surveyId, string $userId, \DateTimeImmutable $now): self
    {
        return new self($id, $surveyId, $userId, self::day($now));
    }

    public static function anonymous(string $id, string $surveyId, \DateTimeImmutable $now): self
    {
        return new self($id, $surveyId, null, self::day($now));
    }

    public function resubmit(\DateTimeImmutable $now): void
    {
        $this->submittedOn = self::day($now);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getSurveyId(): string
    {
        return $this->surveyId;
    }

    public function getUserId(): ?string
    {
        return $this->userId;
    }

    public function getSubmittedOn(): \DateTimeImmutable
    {
        return $this->submittedOn;
    }

    private static function day(\DateTimeImmutable $now): \DateTimeImmutable
    {
        return $now->setTime(0, 0);
    }
}
