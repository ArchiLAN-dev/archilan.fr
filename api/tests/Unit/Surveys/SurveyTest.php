<?php

declare(strict_types=1);

namespace App\Tests\Unit\Surveys;

use App\Surveys\Domain\Entity\Survey;
use App\Surveys\Domain\Entity\SurveyQuestion;
use App\Surveys\Domain\Enum\QuestionKind;
use App\Surveys\Domain\Enum\SurveyAudience;
use App\Surveys\Domain\Enum\SurveyStatus;
use PHPUnit\Framework\TestCase;

/** Story 51.1: a survey's fields, its period, its publication and what publication fixes. */
final class SurveyTest extends TestCase
{
    public function testADraftIsTrimmedAndUnpublished(): void
    {
        $survey = $this->survey(title: '  Quand viens-tu ?  ', intro: ' Dis-nous tout. ');

        self::assertSame('Quand viens-tu ?', $survey->getTitle());
        self::assertSame('Dis-nous tout.', $survey->getIntro());
        self::assertSame(SurveyStatus::Draft, $survey->getStatus());
        self::assertNull($survey->getPublishedAt());
    }

    public function testATitleOutOfBoundsIsRefused(): void
    {
        foreach (['   ', str_repeat('a', Survey::MAX_TITLE + 1)] as $title) {
            try {
                $this->survey(title: $title);
                self::fail('A title out of bounds is refused.');
            } catch (\DomainException $e) {
                self::assertSame('survey_title_invalid', $e->getMessage());
            }
        }
    }

    public function testAnIntroTooLongIsRefused(): void
    {
        $this->expectExceptionMessage('survey_intro_invalid');

        $this->survey(intro: str_repeat('a', Survey::MAX_INTRO + 1));
    }

    public function testAPeriodThatClosesBeforeItOpensIsRefused(): void
    {
        $this->expectExceptionMessage('survey_period_invalid');

        $this->survey(opensAt: new \DateTimeImmutable('2026-11-20'), closesAt: new \DateTimeImmutable('2026-11-20'));
    }

    public function testADraftChangesItsAudienceAndAnonymity(): void
    {
        $survey = $this->survey();

        $survey->revise(null, null, SurveyAudience::Participants, true, false, null, null, new \DateTimeImmutable('2026-11-10'));

        self::assertSame(SurveyAudience::Participants, $survey->getAudience());
        self::assertTrue($survey->isAnonymous());
        self::assertFalse($survey->sendsEmail());
    }

    public function testARevisionChecksThePeriodWithTheDateLeftAsItIs(): void
    {
        $survey = $this->survey();

        $this->expectExceptionMessage('survey_period_invalid');

        $survey->revise(null, null, null, null, null, new \DateTimeImmutable('2026-12-31'), null, new \DateTimeImmutable('2026-11-10'));
    }

    public function testAPublishedSurveyKeepsItsAudienceAndAnonymity(): void
    {
        $survey = $this->published();

        foreach ([[SurveyAudience::Participants, null], [null, true]] as [$audience, $anonymous]) {
            try {
                $survey->revise(null, null, $audience, $anonymous, null, null, null, new \DateTimeImmutable('2026-11-10'));
                self::fail('A fixed field of a published survey does not change.');
            } catch (\DomainException $e) {
                self::assertSame('survey_locked_field', $e->getMessage());
            }
        }

        $survey->revise('Nouveau titre', null, SurveyAudience::Registrants, false, null, null, null, new \DateTimeImmutable('2026-11-10'));
        self::assertSame('Nouveau titre', $survey->getTitle());
    }

    public function testASurveyWithoutQuestionIsNotPublished(): void
    {
        $this->expectExceptionMessage('survey_questions_missing');

        $this->survey()->publish([], [], new \DateTimeImmutable('2026-11-10'));
    }

    public function testAChoiceQuestionNeedsTwoActiveOptionsToBePublished(): void
    {
        $question = SurveyQuestion::ask('q1', 's1', QuestionKind::Multiple, 'Quels jours ?', true, 0);

        $this->expectExceptionMessage('survey_question_options_missing');

        $this->survey()->publish([$question], ['q1' => 1], new \DateTimeImmutable('2026-11-10'));
    }

    public function testAPublishedSurveyIsNotPublishedAgainNorDeleted(): void
    {
        $survey = $this->published();

        self::assertTrue($survey->isPublished());
        self::assertEquals(new \DateTimeImmutable('2026-11-10'), $survey->getPublishedAt());
        try {
            $survey->publish([SurveyQuestion::ask('q2', 's1', QuestionKind::Text, 'Autre chose ?', false, 0)], [], new \DateTimeImmutable('2026-11-11'));
            self::fail('A survey is published once.');
        } catch (\DomainException $e) {
            self::assertSame('survey_already_published', $e->getMessage());
        }

        $this->expectExceptionMessage('survey_published');
        $survey->assertDeletable();
    }

    private function published(): Survey
    {
        $survey = $this->survey();
        $survey->publish([SurveyQuestion::ask('q1', 's1', QuestionKind::Single, 'Tu dors sur place ?', true, 0)], ['q1' => 2], new \DateTimeImmutable('2026-11-10'));

        return $survey;
    }

    private function survey(
        string $title = 'Quand viens-tu ?',
        string $intro = '',
        ?\DateTimeImmutable $opensAt = null,
        ?\DateTimeImmutable $closesAt = null,
    ): Survey {
        return Survey::draft(
            's1',
            'e1',
            $title,
            $intro,
            SurveyAudience::Registrants,
            false,
            true,
            $opensAt ?? new \DateTimeImmutable('2026-11-09'),
            $closesAt ?? new \DateTimeImmutable('2026-11-20'),
            new \DateTimeImmutable('2026-11-09'),
        );
    }
}
