<?php

declare(strict_types=1);

namespace App\Surveys\Domain\Entity;

use App\Surveys\Domain\Enum\QuestionKind;
use Doctrine\ORM\Mapping as ORM;

/**
 * A question of a survey (story 51.1). Its kind changes only while the survey is a draft: answers given to one kind
 * mean nothing to another.
 */
#[ORM\Entity]
#[ORM\Table(name: 'survey_question')]
#[ORM\Index(name: 'idx_survey_question_survey', columns: ['survey_id', 'position'])]
final class SurveyQuestion
{
    public const int MAX_LABEL = 200;
    /** A choice between fewer than two options is no choice. */
    public const int MIN_OPTIONS = 2;

    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 32)]
        private string $id,
        #[ORM\Column(name: 'survey_id', type: 'string', length: 32)]
        private string $surveyId,
        #[ORM\Column(type: 'string', length: 16, enumType: QuestionKind::class)]
        private QuestionKind $kind,
        #[ORM\Column(type: 'string', length: 200)]
        private string $label,
        #[ORM\Column(type: 'boolean')]
        private bool $required,
        #[ORM\Column(type: 'integer')]
        private int $position,
    ) {
    }

    /** @throws \DomainException survey_question_label_invalid */
    public static function ask(string $id, string $surveyId, QuestionKind $kind, string $label, bool $required, int $position): self
    {
        return new self($id, $surveyId, $kind, self::validLabel($label), $required, $position);
    }

    /**
     * Each null field is left as it is.
     *
     * @throws \DomainException survey_question_label_invalid, survey_question_locked
     */
    public function revise(?string $label, ?bool $required, ?QuestionKind $kind, bool $surveyPublished): void
    {
        if ($surveyPublished && null !== $kind && $kind !== $this->kind) {
            throw new \DomainException('survey_question_locked');
        }
        $this->label = null === $label ? $this->label : self::validLabel($label);
        $this->required = $required ?? $this->required;
        $this->kind = $kind ?? $this->kind;
    }

    public function moveTo(int $position): void
    {
        $this->position = $position;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getSurveyId(): string
    {
        return $this->surveyId;
    }

    public function getKind(): QuestionKind
    {
        return $this->kind;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function isRequired(): bool
    {
        return $this->required;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    private static function validLabel(string $label): string
    {
        $label = trim($label);
        if ('' === $label || mb_strlen($label) > self::MAX_LABEL) {
            throw new \DomainException('survey_question_label_invalid');
        }

        return $label;
    }
}
