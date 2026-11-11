<?php

declare(strict_types=1);

namespace App\Tests\Unit\Content;

use App\Content\Domain\Entity\HelpGuide;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Story 53.5: the rules of a help guide. */
final class HelpGuideTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-11-11T10:00:00+00:00');
    }

    public function testAGuideKeepsItsTrimmedFields(): void
    {
        $guide = HelpGuide::create('g1', ' pelles ', ' Les pelles ', ' La monnaie. ', " ## Texte \n", true, 2, $this->now);

        self::assertSame('pelles', $guide->getSlug());
        self::assertSame('Les pelles', $guide->getTitle());
        self::assertSame('La monnaie.', $guide->getSummary());
        self::assertSame('## Texte', $guide->getBody());
        self::assertTrue($guide->isPublished());
        self::assertSame(2, $guide->getPosition());
    }

    #[DataProvider('slugs')]
    public function testTheSlugIsTheEndOfAnAddress(string $slug, bool $accepted): void
    {
        self::assertSame($accepted, HelpGuide::isAcceptedSlug($slug));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function slugs(): iterable
    {
        yield 'words and hyphens' => ['premiere-partie', true];
        yield 'digits' => ['blind-test-2', true];
        yield 'two characters' => ['ab', true];
        yield 'sixty characters' => [str_repeat('a', 60), true];
        yield 'one character' => ['a', false];
        yield 'sixty-one characters' => [str_repeat('a', 61), false];
        yield 'uppercase' => ['Pelles', false];
        yield 'space' => ['les pelles', false];
        yield 'accent' => ['première', false];
        yield 'leading hyphen' => ['-pelles', false];
        yield 'double hyphen' => ['les--pelles', false];
        yield 'slash' => ['aide/pelles', false];
    }

    public function testTheArchipelagoAddressIsReserved(): void
    {
        $this->expectExceptionMessage('help_guide_slug_reserved');
        HelpGuide::create('g1', 'archipelago', 'Titre', 'Résumé', 'Texte', true, 0, $this->now);
    }

    /** @param array{string, string, string, string} $fields */
    #[DataProvider('invalidFields')]
    public function testEachFieldHasItsBounds(array $fields, string $code): void
    {
        $this->expectExceptionMessage($code);
        HelpGuide::create('g1', ...[...$fields, true, 0, $this->now]);
    }

    /** @return iterable<string, array{array{string, string, string, string}, string}> */
    public static function invalidFields(): iterable
    {
        yield 'bad slug' => [['Pelles', 'Titre', 'Résumé', 'Texte'], 'help_guide_slug_invalid'];
        yield 'empty title' => [['pelles', '  ', 'Résumé', 'Texte'], 'help_guide_title_invalid'];
        yield 'long title' => [['pelles', str_repeat('é', 81), 'Résumé', 'Texte'], 'help_guide_title_invalid'];
        yield 'empty summary' => [['pelles', 'Titre', '', 'Texte'], 'help_guide_summary_invalid'];
        yield 'long summary' => [['pelles', 'Titre', str_repeat('é', 201), 'Texte'], 'help_guide_summary_invalid'];
        yield 'empty body' => [['pelles', 'Titre', 'Résumé', "\n"], 'help_guide_body_invalid'];
        yield 'long body' => [['pelles', 'Titre', 'Résumé', str_repeat('a', 20001)], 'help_guide_body_invalid'];
    }

    public function testTheLongestFieldsAreAccepted(): void
    {
        $guide = HelpGuide::create('g1', 'pelles', str_repeat('é', 80), str_repeat('é', 200), str_repeat('a', 20000), false, 0, $this->now);

        self::assertFalse($guide->isPublished());
    }

    public function testAPositionIsNeverNegative(): void
    {
        $guide = HelpGuide::create('g1', 'pelles', 'Titre', 'Résumé', 'Texte', true, 0, $this->now);

        $this->expectExceptionMessage('help_guide_position_invalid');
        $guide->moveTo(-1, $this->now);
    }
}
