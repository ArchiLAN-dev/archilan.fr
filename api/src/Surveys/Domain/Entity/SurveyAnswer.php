<?php

declare(strict_types=1);

namespace App\Surveys\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * One answer of a response (story 51.2): an option chosen (one row per option of a multiple choice), a text, or a
 * mark from 1 to 5. Built from an answer sheet already checked.
 */
#[ORM\Entity]
#[ORM\Table(name: 'survey_answer')]
#[ORM\Index(name: 'idx_survey_answer_response', columns: ['response_id'])]
#[ORM\Index(name: 'idx_survey_answer_option', columns: ['option_id'])]
final class SurveyAnswer
{
    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 32)]
        private string $id,
        #[ORM\Column(name: 'response_id', type: 'string', length: 32)]
        private string $responseId,
        #[ORM\Column(name: 'question_id', type: 'string', length: 32)]
        private string $questionId,
        #[ORM\Column(name: 'option_id', type: 'string', length: 32, nullable: true)]
        private ?string $optionId,
        #[ORM\Column(type: 'text', nullable: true)]
        private ?string $text,
        #[ORM\Column(type: 'smallint', nullable: true)]
        private ?int $rating,
    ) {
    }

    public static function choice(string $id, string $responseId, string $questionId, string $optionId): self
    {
        return new self($id, $responseId, $questionId, $optionId, null, null);
    }

    public static function text(string $id, string $responseId, string $questionId, string $text): self
    {
        return new self($id, $responseId, $questionId, null, $text, null);
    }

    public static function rating(string $id, string $responseId, string $questionId, int $rating): self
    {
        return new self($id, $responseId, $questionId, null, null, $rating);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getResponseId(): string
    {
        return $this->responseId;
    }

    public function getQuestionId(): string
    {
        return $this->questionId;
    }

    public function getOptionId(): ?string
    {
        return $this->optionId;
    }

    public function getText(): ?string
    {
        return $this->text;
    }

    public function getRating(): ?int
    {
        return $this->rating;
    }
}
