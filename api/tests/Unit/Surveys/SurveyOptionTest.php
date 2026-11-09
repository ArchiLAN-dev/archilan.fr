<?php

declare(strict_types=1);

namespace App\Tests\Unit\Surveys;

use App\Surveys\Domain\Entity\SurveyOption;
use PHPUnit\Framework\TestCase;

/** Story 51.1: an option is renamed only while nobody chose it, and retired rather than deleted. */
final class SurveyOptionTest extends TestCase
{
    public function testALabelOutOfBoundsIsRefused(): void
    {
        $this->expectExceptionMessage('survey_option_label_invalid');

        SurveyOption::offer('o1', 'q1', str_repeat('a', SurveyOption::MAX_LABEL + 1), 0);
    }

    public function testAnOptionWithoutAnswersIsRenamed(): void
    {
        $option = SurveyOption::offer('o1', 'q1', 'Samedi', 0);

        $option->rename(' Samedi matin ', false);

        self::assertSame('Samedi matin', $option->getLabel());
    }

    public function testAnAnsweredOptionIsNotRenamed(): void
    {
        $option = SurveyOption::offer('o1', 'q1', 'Samedi', 0);
        $option->rename(' Samedi ', true);
        self::assertSame('Samedi', $option->getLabel());

        $this->expectExceptionMessage('survey_option_answered');

        $option->rename('Dimanche', true);
    }

    public function testRetiringKeepsTheFirstDate(): void
    {
        $option = SurveyOption::offer('o1', 'q1', 'Samedi', 0);
        self::assertFalse($option->isRetired());

        $option->retire(new \DateTimeImmutable('2026-11-10'));
        $option->retire(new \DateTimeImmutable('2026-11-11'));

        self::assertTrue($option->isRetired());
        self::assertEquals(new \DateTimeImmutable('2026-11-10'), $option->getRetiredAt());
    }
}
