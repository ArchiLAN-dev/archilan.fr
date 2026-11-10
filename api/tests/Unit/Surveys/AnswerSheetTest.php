<?php

declare(strict_types=1);

namespace App\Tests\Unit\Surveys;

use App\Surveys\Domain\Entity\SurveyQuestion;
use App\Surveys\Domain\Enum\QuestionKind;
use App\Surveys\Domain\Service\AnswerSheet;
use App\Surveys\Domain\ValueObject\GivenAnswer;
use PHPUnit\Framework\TestCase;

/** Story 51.2: what an answer must be for its question, and what a response must answer. */
final class AnswerSheetTest extends TestCase
{
    public function testValidAnswersAreKeptNormalisedInTheQuestionsOrder(): void
    {
        $kept = AnswerSheet::check($this->questions(), $this->offered(), [
            new GivenAnswer('rating', rating: 4),
            new GivenAnswer('text', text: '  Super soirée.  '),
            new GivenAnswer('days', ['sat', 'sun']),
            new GivenAnswer('sleep', ['yes']),
        ]);

        self::assertSame(['sleep', 'days', 'text', 'rating'], array_map(static fn (GivenAnswer $a): string => $a->questionId, $kept));
        self::assertSame(['sat', 'sun'], $kept[1]->optionIds);
        self::assertSame('Super soirée.', $kept[2]->text);
        self::assertSame(4, $kept[3]->rating);
    }

    public function testAnOptionalQuestionMayBeLeftOrLeftEmpty(): void
    {
        $kept = AnswerSheet::check($this->questions(), $this->offered(), [
            new GivenAnswer('sleep', ['no']),
            new GivenAnswer('text', text: '   '),
            new GivenAnswer('rating'),
        ]);

        self::assertSame(['sleep'], array_map(static fn (GivenAnswer $a): string => $a->questionId, $kept));
    }

    public function testARequiredQuestionNeedsAnAnswer(): void
    {
        $this->expectExceptionMessage('survey_answer_required');

        AnswerSheet::check($this->questions(), $this->offered(), [new GivenAnswer('days', ['sat'])]);
    }

    public function testAResponseAnswersAtLeastOneQuestion(): void
    {
        $optional = [SurveyQuestion::ask('text', 's', QuestionKind::Text, 'Un mot ?', false, 0)];

        $this->expectExceptionMessage('survey_answer_empty');

        AnswerSheet::check($optional, [], []);
    }

    public function testAnAnswerThatDoesNotFitItsQuestionIsRefused(): void
    {
        $cases = [
            'unknown question' => [new GivenAnswer('sleep', ['yes']), new GivenAnswer('elsewhere', text: 'x')],
            'question twice' => [new GivenAnswer('sleep', ['yes']), new GivenAnswer('sleep', ['no'])],
            'two options for a single choice' => [new GivenAnswer('sleep', ['yes', 'no'])],
            'retired option' => [new GivenAnswer('sleep', ['maybe'])],
            'option of another question' => [new GivenAnswer('sleep', ['sat'])],
            'same option twice' => [new GivenAnswer('sleep', ['yes']), new GivenAnswer('days', ['sat', 'sat'])],
            'text on a choice' => [new GivenAnswer('sleep', ['yes'], 'oui')],
            'mark out of range' => [new GivenAnswer('sleep', ['yes']), new GivenAnswer('rating', rating: 6)],
            'option on a mark' => [new GivenAnswer('sleep', ['yes']), new GivenAnswer('rating', ['yes'], rating: 3)],
            'text too long' => [new GivenAnswer('sleep', ['yes']), new GivenAnswer('text', text: str_repeat('a', AnswerSheet::MAX_TEXT + 1))],
        ];
        foreach ($cases as $case => $given) {
            try {
                AnswerSheet::check($this->questions(), $this->offered(), $given);
                self::fail($case.' is refused.');
            } catch (\DomainException $e) {
                self::assertSame('survey_answer_invalid', $e->getMessage(), $case);
            }
        }
    }

    /** @return list<SurveyQuestion> */
    private function questions(): array
    {
        return [
            SurveyQuestion::ask('sleep', 's', QuestionKind::Single, 'Tu dors sur place ?', true, 0),
            SurveyQuestion::ask('days', 's', QuestionKind::Multiple, 'Quels jours ?', false, 1),
            SurveyQuestion::ask('text', 's', QuestionKind::Text, 'Un mot ?', false, 2),
            SurveyQuestion::ask('rating', 's', QuestionKind::Rating, 'Ta note ?', false, 3),
        ];
    }

    /** @return array<string, list<string>> */
    private function offered(): array
    {
        // "maybe" was retired: it is not offered anymore.
        return ['sleep' => ['yes', 'no'], 'days' => ['sat', 'sun']];
    }
}
