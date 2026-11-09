<?php

declare(strict_types=1);

namespace App\Tests\Unit\Surveys;

use App\Surveys\Domain\Entity\SurveyQuestion;
use App\Surveys\Domain\Enum\QuestionKind;
use PHPUnit\Framework\TestCase;

/** Story 51.1: a question's label, and its kind fixed once the survey is published. */
final class SurveyQuestionTest extends TestCase
{
    public function testALabelOutOfBoundsIsRefused(): void
    {
        foreach (['', str_repeat('a', SurveyQuestion::MAX_LABEL + 1)] as $label) {
            try {
                SurveyQuestion::ask('q1', 's1', QuestionKind::Text, $label, false, 0);
                self::fail('A label out of bounds is refused.');
            } catch (\DomainException $e) {
                self::assertSame('survey_question_label_invalid', $e->getMessage());
            }
        }
    }

    public function testADraftQuestionChangesItsKind(): void
    {
        $question = SurveyQuestion::ask('q1', 's1', QuestionKind::Single, 'Tu dors sur place ?', false, 0);

        $question->revise(' Tu dors où ? ', true, QuestionKind::Text, false);

        self::assertSame('Tu dors où ?', $question->getLabel());
        self::assertTrue($question->isRequired());
        self::assertSame(QuestionKind::Text, $question->getKind());
    }

    public function testAPublishedQuestionKeepsItsKindButNotItsLabel(): void
    {
        $question = SurveyQuestion::ask('q1', 's1', QuestionKind::Single, 'Tu dors sur place ?', false, 0);
        $question->revise('Tu dors sur place, vraiment ?', null, QuestionKind::Single, true);
        self::assertSame('Tu dors sur place, vraiment ?', $question->getLabel());

        $this->expectExceptionMessage('survey_question_locked');

        $question->revise(null, null, QuestionKind::Multiple, true);
    }

    public function testOnlyAChoiceHasOptions(): void
    {
        self::assertTrue(QuestionKind::Single->hasOptions());
        self::assertTrue(QuestionKind::Multiple->hasOptions());
        self::assertFalse(QuestionKind::Text->hasOptions());
        self::assertFalse(QuestionKind::Rating->hasOptions());
    }
}
