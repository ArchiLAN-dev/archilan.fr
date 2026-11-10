<?php

declare(strict_types=1);

namespace App\Surveys\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * An option of a choice question (story 51.1). Its id is what an answer points at, so an option that has answers
 * is never renamed (an answer "Saturday" would silently become "Sunday"): it is retired, its answers kept, and a new
 * option is added.
 */
#[ORM\Entity]
#[ORM\Table(name: 'survey_option')]
#[ORM\Index(name: 'idx_survey_option_question', columns: ['question_id', 'position'])]
final class SurveyOption
{
    public const int MAX_LABEL = 80;

    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 32)]
        private string $id,
        #[ORM\Column(name: 'question_id', type: 'string', length: 32)]
        private string $questionId,
        #[ORM\Column(type: 'string', length: 80)]
        private string $label,
        #[ORM\Column(type: 'integer')]
        private int $position,
        #[ORM\Column(name: 'retired_at', type: 'datetimetz_immutable', nullable: true)]
        private ?\DateTimeImmutable $retiredAt,
    ) {
    }

    /** @throws \DomainException survey_option_label_invalid */
    public static function offer(string $id, string $questionId, string $label, int $position): self
    {
        return new self($id, $questionId, self::validLabel($label), $position, null);
    }

    /** @throws \DomainException survey_option_label_invalid, survey_option_answered */
    public function rename(string $label, bool $hasAnswers): void
    {
        $label = self::validLabel($label);
        if ($label === $this->label) {
            return;
        }
        if ($hasAnswers) {
            throw new \DomainException('survey_option_answered');
        }
        $this->label = $label;
    }

    /** Retiring a retired option changes nothing. */
    public function retire(\DateTimeImmutable $now): void
    {
        $this->retiredAt ??= $now;
    }

    public function moveTo(int $position): void
    {
        $this->position = $position;
    }

    public function isRetired(): bool
    {
        return null !== $this->retiredAt;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getQuestionId(): string
    {
        return $this->questionId;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getRetiredAt(): ?\DateTimeImmutable
    {
        return $this->retiredAt;
    }

    private static function validLabel(string $label): string
    {
        $label = trim($label);
        if ('' === $label || mb_strlen($label) > self::MAX_LABEL) {
            throw new \DomainException('survey_option_label_invalid');
        }

        return $label;
    }
}
