<?php

declare(strict_types=1);

namespace App\Surveys\Domain\Service;

use App\Surveys\Domain\Entity\SurveyQuestion;
use App\Surveys\Domain\Enum\QuestionKind;
use App\Surveys\Domain\ValueObject\GivenAnswer;

/**
 * Checks a member's answers against the survey's questions (story 51.2) and keeps only what they mean: a single
 * choice is one option still offered, a multiple choice at least one, a text 1 to 1000 characters once trimmed, a
 * mark from 1 to 5. Every required question needs an answer, and a response answers at least one question.
 */
final class AnswerSheet
{
    public const int MAX_TEXT = 1000;

    /**
     * @param list<SurveyQuestion>        $questions
     * @param array<string, list<string>> $activeOptionIds per question id, the options still offered
     * @param list<GivenAnswer>           $given
     *
     * @return list<GivenAnswer> the answers kept, normalised, in the questions' order
     *
     * @throws \DomainException survey_answer_invalid, survey_answer_required, survey_answer_empty
     */
    public static function check(array $questions, array $activeOptionIds, array $given): array
    {
        $byQuestion = [];
        foreach ($given as $answer) {
            if (isset($byQuestion[$answer->questionId])) {
                throw new \DomainException('survey_answer_invalid');
            }
            $byQuestion[$answer->questionId] = $answer;
        }
        $known = array_map(static fn (SurveyQuestion $q): string => $q->getId(), $questions);
        if ([] !== array_diff(array_keys($byQuestion), $known)) {
            throw new \DomainException('survey_answer_invalid');
        }

        $kept = [];
        foreach ($questions as $question) {
            $answer = $byQuestion[$question->getId()] ?? null;
            if (null === $answer || $answer->isEmpty()) {
                if ($question->isRequired()) {
                    throw new \DomainException('survey_answer_required');
                }
                continue;
            }
            $kept[] = self::normalised($question, $activeOptionIds[$question->getId()] ?? [], $answer);
        }
        if ([] === $kept) {
            throw new \DomainException('survey_answer_empty');
        }

        return $kept;
    }

    /** @param list<string> $offered */
    private static function normalised(SurveyQuestion $question, array $offered, GivenAnswer $answer): GivenAnswer
    {
        $id = $question->getId();

        return match ($question->getKind()) {
            QuestionKind::Single, QuestionKind::Multiple => new GivenAnswer($id, self::choices($question->getKind(), $offered, $answer)),
            QuestionKind::Text => new GivenAnswer($id, text: self::text($answer)),
            QuestionKind::Rating => new GivenAnswer($id, rating: self::rating($answer)),
        };
    }

    /**
     * @param list<string> $offered
     *
     * @return list<string>
     */
    private static function choices(QuestionKind $kind, array $offered, GivenAnswer $answer): array
    {
        $chosen = $answer->optionIds;
        $valid = null === $answer->text && null === $answer->rating
            && count(array_unique($chosen)) === count($chosen)
            && [] === array_diff($chosen, $offered)
            && (QuestionKind::Multiple === $kind || 1 === count($chosen));
        if (!$valid) {
            throw new \DomainException('survey_answer_invalid');
        }

        return $chosen;
    }

    private static function text(GivenAnswer $answer): string
    {
        $text = trim($answer->text ?? '');
        if ([] !== $answer->optionIds || null !== $answer->rating || '' === $text || mb_strlen($text) > self::MAX_TEXT) {
            throw new \DomainException('survey_answer_invalid');
        }

        return $text;
    }

    private static function rating(GivenAnswer $answer): int
    {
        $rating = $answer->rating;
        if ([] !== $answer->optionIds || null !== $answer->text || null === $rating || $rating < 1 || $rating > 5) {
            throw new \DomainException('survey_answer_invalid');
        }

        return $rating;
    }
}
