<?php

declare(strict_types=1);

namespace App\Surveys\Application\Command;

use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\ForbiddenException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Exception\ValidationException;
use App\Surveys\Application\Support\SurveyGate;
use App\Surveys\Domain\Entity\SurveyAnswer;
use App\Surveys\Domain\Entity\SurveyParticipation;
use App\Surveys\Domain\Entity\SurveyQuestion;
use App\Surveys\Domain\Entity\SurveyResponse;
use App\Surveys\Domain\Repository\SurveyRepositoryInterface;
use App\Surveys\Domain\Repository\SurveyResponseRepositoryInterface;
use App\Surveys\Domain\Service\AnswerSheet;
use App\Surveys\Domain\ValueObject\GivenAnswer;
use Psr\Clock\ClockInterface;

/**
 * A member answers a survey (story 51.2). A nominative answer replaces the previous one until the survey closes. An
 * anonymous answer is given once: its response carries nobody, and its ids are random so that nothing sorts it
 * next to the participation written in the same unit of work.
 */
final readonly class AnswerSurvey
{
    private const array MESSAGES = [
        'survey_answer_invalid' => 'Une réponse ne correspond pas à sa question.',
        'survey_answer_required' => 'Réponds aux questions obligatoires.',
        'survey_answer_empty' => 'Réponds au moins à une question.',
    ];

    public function __construct(
        private SurveyRepositoryInterface $surveys,
        private SurveyResponseRepositoryInterface $responses,
        private SurveyGate $gate,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param list<SubmittedAnswer> $submitted
     *
     * @throws NotFoundException   when the survey does not exist or is a draft
     * @throws ForbiddenException  when the member is not in its audience
     * @throws ConflictException   when the survey is not open, or an anonymous survey was already answered
     * @throws ValidationException when an answer is invalid or a required one is missing
     */
    public function answer(string $surveyId, string $userId, array $submitted): void
    {
        $survey = $this->surveys->find($surveyId) ?? throw new NotFoundException('Sondage introuvable.', 'survey_not_found');
        $now = $this->clock->now();
        $this->gate->assertAnswerable($survey->isPublished(), $survey->getOpensAt(), $survey->getClosesAt(), $survey->getAudience(), $survey->getEventId(), $userId, $now);

        $questions = $this->surveys->questionsOf($surveyId);
        $offered = [];
        foreach ($this->surveys->optionsOf(array_map(static fn (SurveyQuestion $q): string => $q->getId(), $questions)) as $option) {
            if (!$option->isRetired()) {
                $offered[$option->getQuestionId()][] = $option->getId();
            }
        }
        $given = array_map(static fn (SubmittedAnswer $a): GivenAnswer => new GivenAnswer($a->questionId, $a->optionIds, $a->text, $a->rating), $submitted);
        try {
            $kept = AnswerSheet::check($questions, $offered, $given);
        } catch (\DomainException $e) {
            throw new ValidationException(self::MESSAGES[$e->getMessage()] ?? 'Réponse invalide.', [], $e->getMessage());
        }

        $participation = $this->responses->findParticipation($surveyId, $userId);
        if ($survey->isAnonymous()) {
            if (null !== $participation) {
                throw new ConflictException('Tu as déjà répondu à ce sondage anonyme.', 'survey_already_answered');
            }
            $response = SurveyResponse::anonymous(self::randomId(), $surveyId, $now);
            $this->responses->record(
                SurveyParticipation::record(self::sortedId($now), $surveyId, $userId, $now),
                $response,
                self::answers($response->getId(), $kept, static fn (): string => self::randomId()),
                [],
            );

            return;
        }

        $response = $this->responses->findNominativeResponse($surveyId, $userId);
        $replaced = null === $response ? [] : $this->responses->answersOf($response->getId());
        $response?->resubmit($now);
        $response ??= SurveyResponse::nominative(self::sortedId($now), $surveyId, $userId, $now);
        $participation?->renew($now);
        $this->responses->record(
            $participation ?? SurveyParticipation::record(self::sortedId($now), $surveyId, $userId, $now),
            $response,
            self::answers($response->getId(), $kept, static fn (): string => self::sortedId($now)),
            $replaced,
        );
    }

    /**
     * @param list<GivenAnswer>  $kept
     * @param callable(): string $newId
     *
     * @return list<SurveyAnswer>
     */
    private static function answers(string $responseId, array $kept, callable $newId): array
    {
        $answers = [];
        foreach ($kept as $answer) {
            foreach ($answer->optionIds as $optionId) {
                $answers[] = SurveyAnswer::choice($newId(), $responseId, $answer->questionId, $optionId);
            }
            if (null !== $answer->text) {
                $answers[] = SurveyAnswer::text($newId(), $responseId, $answer->questionId, $answer->text);
            }
            if (null !== $answer->rating) {
                $answers[] = SurveyAnswer::rating($newId(), $responseId, $answer->questionId, $answer->rating);
            }
        }

        return $answers;
    }

    /** 32 hex characters that sort in time, as the admin notes do (story 46.1). */
    private static function sortedId(\DateTimeImmutable $now): string
    {
        return sprintf('%014x', (int) $now->format('Uu')).bin2hex(random_bytes(9));
    }

    /** 32 random hex characters: an anonymous response keeps no trace of when it was written. */
    private static function randomId(): string
    {
        return bin2hex(random_bytes(16));
    }
}
