<?php

declare(strict_types=1);

namespace App\Surveys\Application\Command;

use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Exception\ValidationException;
use App\Surveys\Application\Port\SurveyAnswerCountInterface;
use App\Surveys\Application\Port\SurveyEventInterface;
use App\Surveys\Application\Support\SurveyEventPeriod;
use App\Surveys\Domain\Entity\Survey;
use App\Surveys\Domain\Entity\SurveyOption;
use App\Surveys\Domain\Entity\SurveyQuestion;
use App\Surveys\Domain\Enum\QuestionKind;
use App\Surveys\Domain\Enum\SurveyAudience;
use App\Surveys\Domain\Repository\SurveyRepositoryInterface;
use Psr\Clock\ClockInterface;

/**
 * Writes the surveys of an event (story 51.1): the survey, its questions and their options. Each call is one unit of
 * work. A draft changes freely; a published survey keeps its audience, its anonymity, its questions and their kinds,
 * and an option that has answers is retired rather than renamed or deleted.
 */
final readonly class ManageSurveys
{
    /** A survey with no closing date given stays open this long. */
    private const string DEFAULT_LENGTH = '+7 days';

    private const array MESSAGES = [
        'survey_title_invalid' => 'Un titre de 1 à 120 caractères.',
        'survey_intro_invalid' => 'Une introduction de 1000 caractères au plus.',
        'survey_period_invalid' => 'Des dates valides, la fermeture après l\'ouverture.',
        'survey_audience_invalid' => 'Public inconnu.',
        'survey_questions_missing' => 'Un sondage publié a au moins une question.',
        'survey_question_options_missing' => 'Une question à choix a au moins deux options.',
        'survey_question_label_invalid' => 'Une question de 1 à 200 caractères.',
        'survey_question_kind_invalid' => 'Type de question inconnu.',
        'survey_question_has_no_options' => 'Seule une question à choix a des options.',
        'survey_option_label_invalid' => 'Une option de 1 à 80 caractères.',
        'survey_order_invalid' => 'L\'ordre doit reprendre chaque élément une fois.',
    ];

    private const array CONFLICTS = [
        'survey_already_published' => 'Ce sondage est déjà publié.',
        'survey_published' => 'Un sondage publié ne se supprime pas.',
        'survey_locked_field' => 'Le public et l\'anonymat ne changent plus une fois le sondage publié.',
        'survey_question_locked' => 'Une question d\'un sondage publié ne se supprime pas et ne change pas de type.',
        'survey_option_answered' => 'Une option qui a des réponses ne se renomme pas : retire-la et ajoutes-en une.',
    ];

    public function __construct(
        private SurveyRepositoryInterface $surveys,
        private SurveyEventInterface $events,
        private SurveyAnswerCountInterface $answers,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return string the id of the new survey, a draft
     *
     * @throws NotFoundException   when the event does not exist
     * @throws ValidationException when a field is invalid
     */
    public function create(string $eventId, SurveyDraft $draft): string
    {
        $event = $this->events->period($eventId) ?? throw new NotFoundException('Événement introuvable.', 'event_not_found');
        $now = $this->clock->now();
        $audience = null === $draft->audience ? SurveyAudience::Registrants : self::audience($draft->audience);
        [$defaultOpens, $defaultCloses] = self::defaultPeriod($audience, $event, $now);

        $survey = $this->attempt(static fn () => Survey::draft(
            self::newId($now),
            $eventId,
            $draft->title ?? '',
            $draft->intro ?? '',
            $audience,
            $draft->anonymous ?? false,
            $draft->sendEmail ?? true,
            null === $draft->opensAt ? $defaultOpens : self::moment($draft->opensAt),
            null === $draft->closesAt ? $defaultCloses : self::moment($draft->closesAt),
            $now,
        ));
        $this->surveys->save($survey);

        return $survey->getId();
    }

    /**
     * @throws NotFoundException   when the survey does not exist
     * @throws ValidationException when a field is invalid
     * @throws ConflictException   when a fixed field of a published survey would change
     */
    public function revise(string $surveyId, SurveyDraft $draft): void
    {
        $survey = $this->survey($surveyId);
        $audience = null === $draft->audience ? null : self::audience($draft->audience);
        $opensAt = null === $draft->opensAt ? null : self::moment($draft->opensAt);
        $closesAt = null === $draft->closesAt ? null : self::moment($draft->closesAt);
        $now = $this->clock->now();
        $this->attempt(static fn () => $survey->revise($draft->title, $draft->intro, $audience, $draft->anonymous, $draft->sendEmail, $opensAt, $closesAt, $now));
        $this->surveys->save($survey);
    }

    /**
     * @throws NotFoundException   when the survey does not exist
     * @throws ValidationException when a question or its options are missing
     * @throws ConflictException   when the survey is already published
     */
    public function publish(string $surveyId): void
    {
        $survey = $this->survey($surveyId);
        $questions = $this->surveys->questionsOf($surveyId);
        $active = [];
        foreach ($this->surveys->optionsOf(self::ids($questions)) as $option) {
            if (!$option->isRetired()) {
                $active[$option->getQuestionId()] = ($active[$option->getQuestionId()] ?? 0) + 1;
            }
        }
        $now = $this->clock->now();
        $this->attempt(static fn () => $survey->publish($questions, $active, $now));
        $this->surveys->save($survey);
    }

    /**
     * A draft goes with its questions and their options.
     *
     * @throws NotFoundException when the survey does not exist
     * @throws ConflictException when the survey is published
     */
    public function delete(string $surveyId): void
    {
        $survey = $this->survey($surveyId);
        $this->attempt(static fn () => $survey->assertDeletable());
        $questions = $this->surveys->questionsOf($surveyId);
        $this->surveys->delete([$survey, ...$questions, ...$this->surveys->optionsOf(self::ids($questions))]);
    }

    /**
     * @param list<string> $options the labels of a choice question's first options
     *
     * @return string the id of the new question, last of the survey
     *
     * @throws NotFoundException   when the survey does not exist
     * @throws ValidationException when the kind, the label or an option is invalid
     */
    public function addQuestion(string $surveyId, string $kind, string $label, bool $required, array $options): string
    {
        $survey = $this->survey($surveyId);
        $kind = self::kind($kind);
        if ([] !== $options && !$kind->hasOptions()) {
            throw $this->failure(new \DomainException('survey_question_has_no_options'));
        }
        $now = $this->clock->now();
        $position = count($this->surveys->questionsOf($surveyId));
        $question = $this->attempt(static fn () => SurveyQuestion::ask(self::newId($now), $surveyId, $kind, $label, $required, $position));
        $offered = [];
        foreach ($options as $index => $optionLabel) {
            $offered[] = $this->attempt(static fn () => SurveyOption::offer(self::newId($now), $question->getId(), $optionLabel, $index));
        }
        $survey->touch($now);
        $this->surveys->save($survey, $question, ...$offered);

        return $question->getId();
    }

    /**
     * A question of a draft turned into a text or a rating question loses its options.
     *
     * @throws NotFoundException   when the survey or the question does not exist
     * @throws ValidationException when the kind or the label is invalid
     * @throws ConflictException   when the kind of a published survey's question would change
     */
    public function reviseQuestion(string $surveyId, string $questionId, ?string $label, ?bool $required, ?string $kind): void
    {
        $survey = $this->survey($surveyId);
        $question = $this->question($survey, $questionId);
        $kind = null === $kind ? null : self::kind($kind);
        $this->attempt(static fn () => $question->revise($label, $required, $kind, $survey->isPublished()));
        $survey->touch($this->clock->now());
        $dropped = $question->getKind()->hasOptions() ? [] : $this->surveys->optionsOf([$questionId]);
        $this->surveys->delete($dropped, $survey, $question);
    }

    /**
     * Only from a draft: a published survey's question may already have answers.
     *
     * @throws NotFoundException when the survey or the question does not exist
     * @throws ConflictException when the survey is published
     */
    public function deleteQuestion(string $surveyId, string $questionId): void
    {
        $survey = $this->survey($surveyId);
        $question = $this->question($survey, $questionId);
        if ($survey->isPublished()) {
            throw $this->failure(new \DomainException('survey_question_locked'));
        }
        $kept = array_values(array_filter($this->surveys->questionsOf($surveyId), static fn (SurveyQuestion $q): bool => $q->getId() !== $questionId));
        foreach ($kept as $position => $remaining) {
            $remaining->moveTo($position);
        }
        $survey->touch($this->clock->now());
        $this->surveys->delete([$question, ...$this->surveys->optionsOf([$questionId])], $survey, ...$kept);
    }

    /**
     * @param list<string> $questionIds every question of the survey, once, in the new order
     *
     * @throws NotFoundException   when the survey does not exist
     * @throws ValidationException when the list is not the survey's questions
     */
    public function orderQuestions(string $surveyId, array $questionIds): void
    {
        $survey = $this->survey($surveyId);
        $questions = self::reorder($this->surveys->questionsOf($surveyId), $questionIds);
        $survey->touch($this->clock->now());
        $this->surveys->save($survey, ...$questions);
    }

    /**
     * @return string the id of the new option, last of its question
     *
     * @throws NotFoundException   when the survey or the question does not exist
     * @throws ValidationException when the question takes no option or the label is invalid
     */
    public function addOption(string $surveyId, string $questionId, string $label): string
    {
        $survey = $this->survey($surveyId);
        $question = $this->question($survey, $questionId);
        if (!$question->getKind()->hasOptions()) {
            throw $this->failure(new \DomainException('survey_question_has_no_options'));
        }
        $now = $this->clock->now();
        $position = count($this->surveys->optionsOf([$questionId]));
        $option = $this->attempt(static fn () => SurveyOption::offer(self::newId($now), $questionId, $label, $position));
        $survey->touch($now);
        $this->surveys->save($survey, $option);

        return $option->getId();
    }

    /**
     * @throws NotFoundException   when the survey or the option does not exist
     * @throws ValidationException when the label is invalid
     * @throws ConflictException   when the option already has answers
     */
    public function renameOption(string $surveyId, string $optionId, string $label): void
    {
        $survey = $this->survey($surveyId);
        $option = $this->option($survey, $optionId);
        $hasAnswers = $this->answers->optionHasAnswers($optionId);
        $this->attempt(static fn () => $option->rename($label, $hasAnswers));
        $survey->touch($this->clock->now());
        $this->surveys->save($survey, $option);
    }

    /**
     * A draft's option is deleted; a published survey's option is retired, its answers kept.
     *
     * @throws NotFoundException when the survey or the option does not exist
     */
    public function removeOption(string $surveyId, string $optionId): void
    {
        $survey = $this->survey($surveyId);
        $option = $this->option($survey, $optionId);
        $now = $this->clock->now();
        $survey->touch($now);
        if ($survey->isPublished()) {
            $option->retire($now);
            $this->surveys->save($survey, $option);

            return;
        }
        $kept = array_values(array_filter($this->surveys->optionsOf([$option->getQuestionId()]), static fn (SurveyOption $o): bool => $o->getId() !== $optionId));
        foreach ($kept as $position => $remaining) {
            $remaining->moveTo($position);
        }
        $this->surveys->delete([$option], $survey, ...$kept);
    }

    /**
     * @param list<string> $optionIds every option of the question (retired ones included), once, in the new order
     *
     * @throws NotFoundException   when the survey or the question does not exist
     * @throws ValidationException when the list is not the question's options
     */
    public function orderOptions(string $surveyId, string $questionId, array $optionIds): void
    {
        $survey = $this->survey($surveyId);
        $this->question($survey, $questionId);
        $options = self::reorder($this->surveys->optionsOf([$questionId]), $optionIds);
        $survey->touch($this->clock->now());
        $this->surveys->save($survey, ...$options);
    }

    private function survey(string $surveyId): Survey
    {
        return $this->surveys->find($surveyId) ?? throw new NotFoundException('Sondage introuvable.', 'survey_not_found');
    }

    private function question(Survey $survey, string $questionId): SurveyQuestion
    {
        $question = $this->surveys->findQuestion($questionId);
        if (null === $question || $question->getSurveyId() !== $survey->getId()) {
            throw new NotFoundException('Question introuvable.', 'survey_question_not_found');
        }

        return $question;
    }

    private function option(Survey $survey, string $optionId): SurveyOption
    {
        $option = $this->surveys->findOption($optionId);
        $question = null === $option ? null : $this->surveys->findQuestion($option->getQuestionId());
        if (null === $option || null === $question || $question->getSurveyId() !== $survey->getId()) {
            throw new NotFoundException('Option introuvable.', 'survey_option_not_found');
        }

        return $option;
    }

    /**
     * @template T
     *
     * @param callable(): T $change
     *
     * @return T
     */
    private function attempt(callable $change): mixed
    {
        try {
            return $change();
        } catch (\DomainException $e) {
            throw $this->failure($e);
        }
    }

    private function failure(\DomainException $e): ValidationException|ConflictException
    {
        $code = $e->getMessage();
        if (isset(self::CONFLICTS[$code])) {
            return new ConflictException(self::CONFLICTS[$code], $code);
        }

        return new ValidationException(self::MESSAGES[$code] ?? 'Sondage invalide.', [], $code);
    }

    /**
     * Before the event, a survey runs from now to its start; after it, the week after its end.
     *
     * @return array{\DateTimeImmutable, \DateTimeImmutable}
     */
    private static function defaultPeriod(SurveyAudience $audience, SurveyEventPeriod $event, \DateTimeImmutable $now): array
    {
        if (SurveyAudience::Participants === $audience) {
            return [$event->endsAt, $event->endsAt->modify(self::DEFAULT_LENGTH)];
        }

        return [$now, $event->startsAt > $now ? $event->startsAt : $now->modify(self::DEFAULT_LENGTH)];
    }

    /**
     * @template T of SurveyQuestion|SurveyOption
     *
     * @param list<T>      $items
     * @param list<string> $ids
     *
     * @return list<T>
     */
    private static function reorder(array $items, array $ids): array
    {
        $byId = [];
        foreach ($items as $item) {
            $byId[$item->getId()] = $item;
        }
        if (count($ids) !== count($byId) || count(array_unique($ids)) !== count($ids) || [] !== array_diff($ids, array_keys($byId))) {
            throw new ValidationException(self::MESSAGES['survey_order_invalid'], [], 'survey_order_invalid');
        }
        $ordered = [];
        foreach ($ids as $position => $id) {
            $byId[$id]->moveTo($position);
            $ordered[] = $byId[$id];
        }

        return $ordered;
    }

    /**
     * @param list<SurveyQuestion> $questions
     *
     * @return list<string>
     */
    private static function ids(array $questions): array
    {
        return array_map(static fn (SurveyQuestion $q): string => $q->getId(), $questions);
    }

    private static function audience(string $value): SurveyAudience
    {
        return SurveyAudience::tryFrom($value) ?? throw new ValidationException(self::MESSAGES['survey_audience_invalid'], [], 'survey_audience_invalid');
    }

    private static function kind(string $value): QuestionKind
    {
        return QuestionKind::tryFrom($value) ?? throw new ValidationException(self::MESSAGES['survey_question_kind_invalid'], [], 'survey_question_kind_invalid');
    }

    /** An ISO 8601 date with its time, as the admin screen sends it. */
    private static function moment(string $value): \DateTimeImmutable
    {
        if (1 === preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', $value)) {
            try {
                return new \DateTimeImmutable($value);
            } catch (\Exception) {
            }
        }

        throw new ValidationException(self::MESSAGES['survey_period_invalid'], [], 'survey_period_invalid');
    }

    /** 32 hex characters that sort in time, as the admin notes do (story 46.1). */
    private static function newId(\DateTimeImmutable $now): string
    {
        return sprintf('%014x', (int) $now->format('Uu')).bin2hex(random_bytes(9));
    }
}
