<?php

declare(strict_types=1);

namespace App\Tests\Unit\GameSelection;

use App\GameSelection\Domain\Service\DefaultYamlEquivalence;
use PHPUnit\Framework\TestCase;

/**
 * Stories 38.7 and 38.4: does a player's YAML say the same thing as the game's default one?
 *
 * "The same thing", not "the same text": the editor may rewrite a template it did not change - drop the
 * zero weights, write a single choice as a plain value, reorder the keys.
 */
final class DefaultYamlEquivalenceTest extends TestCase
{
    private const array DEFAULT = [
        'name' => 'Player{number}',
        'description' => 'Default Crystal Project Template',
        'game' => 'Crystal Project',
        'requires' => ['version' => '0.6.7'],
        'Crystal Project' => [
            'goal' => ['astley' => 50, 'true_astley' => 0, 'clamshells' => 0],
            'starting_level' => [3 => 50, 'random' => 0],
            'skip_intro_crawl' => ['false' => 50, 'true' => 0],
        ],
    ];

    public function testTheTemplateItselfIsEquivalent(): void
    {
        self::assertTrue(DefaultYamlEquivalence::isEquivalent(self::DEFAULT, self::DEFAULT));
    }

    public function testTheNameDescriptionAndRequiresDoNotCount(): void
    {
        $player = self::DEFAULT;
        $player['name'] = 'Jean';
        $player['description'] = 'Mon slot';
        unset($player['requires']);

        self::assertTrue(DefaultYamlEquivalence::isEquivalent($player, self::DEFAULT));
    }

    public function testZeroWeightsAndPlainValuesAreTheSameChoice(): void
    {
        $player = self::DEFAULT;
        $player['Crystal Project'] = [
            'skip_intro_crawl' => false,
            'starting_level' => 3,
            'goal' => 'astley',
        ];

        self::assertTrue(DefaultYamlEquivalence::isEquivalent($player, self::DEFAULT));
    }

    public function testReorderedWeightsAreEquivalent(): void
    {
        $player = self::DEFAULT;
        $player['Crystal Project']['goal'] = ['clamshells' => 0, 'true_astley' => 0, 'astley' => 50];

        self::assertTrue(DefaultYamlEquivalence::isEquivalent($player, self::DEFAULT));
    }

    public function testOneChangedChoiceIsNotEquivalent(): void
    {
        $player = self::DEFAULT;
        $player['Crystal Project']['goal'] = ['astley' => 0, 'true_astley' => 50, 'clamshells' => 0];

        self::assertFalse(DefaultYamlEquivalence::isEquivalent($player, self::DEFAULT));
    }

    public function testAnAddedOptionIsNotEquivalent(): void
    {
        $player = self::DEFAULT;
        $player['Crystal Project']['kill_bosses_mode'] = 'true';

        self::assertFalse(DefaultYamlEquivalence::isEquivalent($player, self::DEFAULT));
    }

    public function testAnotherGameIsNotEquivalent(): void
    {
        $player = self::DEFAULT;
        $player['game'] = 'Pokemon Crystal';

        self::assertFalse(DefaultYamlEquivalence::isEquivalent($player, self::DEFAULT));
    }

    public function testAMissingGameSectionIsNotEquivalent(): void
    {
        $player = self::DEFAULT;
        unset($player['Crystal Project']);

        self::assertFalse(DefaultYamlEquivalence::isEquivalent($player, self::DEFAULT));
    }
}
