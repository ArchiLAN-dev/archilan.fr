<?php

declare(strict_types=1);

namespace App\Tests\Unit\GameSelection;

use App\GameSelection\Domain\Enum\SlotYamlCase;
use App\GameSelection\Domain\Enum\SlotYamlProblem;
use App\GameSelection\Domain\Service\SlotYamlCompatibility;
use App\GameSelection\Domain\ValueObject\SlotYamlIssue;
use PHPUnit\Framework\TestCase;

/**
 * Story 38.7: when a game switches apworld, what happens to a slot's YAML in a run not yet launched.
 *
 * 1. Never touched by the player: it becomes the new default YAML.
 * 2. Customised, and every setting still exists with an accepted value: kept exactly as it is.
 * 3. Customised, and a setting is gone or refused: kept as it is, and the player is asked to review it.
 */
final class SlotYamlCompatibilityTest extends TestCase
{
    private const array OLD_DEFAULT = [
        'name' => 'Player{number}',
        'game' => 'Crystal Project',
        'Crystal Project' => [
            'goal' => ['astley' => 50, 'true_astley' => 0],
            'starting_level' => [3 => 50],
            'removed_later' => ['false' => 50, 'true' => 0],
        ],
    ];

    private const array NEW_DEFAULT = [
        'name' => 'Player{number}',
        'game' => 'Crystal Project',
        'Crystal Project' => [
            'progression_balancing' => ['normal' => 50],
            'goal' => ['astley' => 50, 'true_astley' => 0, 'clamshells' => 0],
            'starting_level' => [3 => 50],
            'skip_quizard_quiz' => ['false' => 50, 'true' => 0],
            'game_options' => ['default' => 50],
        ],
    ];

    private const array NEW_TYPES = [
        'goal' => ['type' => 'choice', 'values' => ['astley', 'true_astley', 'clamshells']],
        'starting_level' => ['type' => 'range', 'min' => 3, 'max' => 99],
        'skip_quizard_quiz' => ['type' => 'toggle'],
        'game_options' => ['type' => 'dict', 'values' => ['difficulty', 'speed'], 'keys' => ['difficulty' => ['values' => ['easy', 'hard']]]],
    ];

    public function testAnEmptyYamlTakesTheNewDefault(): void
    {
        $verdict = $this->classify(null);

        self::assertSame(SlotYamlCase::ReplaceWithDefault, $verdict->case);
    }

    public function testAYamlEquivalentToTheOldDefaultTakesTheNewDefault(): void
    {
        $player = self::OLD_DEFAULT;
        $player['name'] = 'Jean';

        self::assertSame(SlotYamlCase::ReplaceWithDefault, $this->classify($player)->case);
    }

    public function testACustomYamlWhoseSettingsStillHoldIsKept(): void
    {
        $verdict = $this->classify($this->player([
            'goal' => 'true_astley',
            'starting_level' => 42,
            'skip_quizard_quiz' => 'true',
        ]));

        self::assertSame(SlotYamlCase::Keep, $verdict->case);
        self::assertSame([], $verdict->issues);
    }

    public function testASettingTheNewVersionNoLongerHasNeedsReview(): void
    {
        // Judged against the new template, which lists every option - common ones included.
        $verdict = $this->classify($this->player(['goal' => 'astley', 'removed_later' => 'true', 'progression_balancing' => 'normal']));

        self::assertSame(SlotYamlCase::NeedsReview, $verdict->case);
        self::assertEquals([new SlotYamlIssue('removed_later', SlotYamlProblem::RemovedOption, null)], $verdict->issues);
    }

    public function testAChoiceTheNewVersionRefusesNeedsReview(): void
    {
        $verdict = $this->classify($this->player(['goal' => ['astley' => 10, 'moon' => 40, 'gone' => 0]]));

        self::assertSame(SlotYamlCase::NeedsReview, $verdict->case);
        self::assertEquals([new SlotYamlIssue('goal', SlotYamlProblem::UnknownValue, 'moon')], $verdict->issues, 'a zero weight is never drawn');
    }

    public function testAValueOutsideTheNewBoundsNeedsReview(): void
    {
        $verdict = $this->classify($this->player(['starting_level' => 120]));

        self::assertEquals([new SlotYamlIssue('starting_level', SlotYamlProblem::OutOfRange, '120')], $verdict->issues);
    }

    public function testArchipelagoRandomValuesAreAlwaysAccepted(): void
    {
        $verdict = $this->classify($this->player(['goal' => 'random', 'starting_level' => ['random-range-3-10' => 50, 'random-low' => 50]]));

        self::assertSame(SlotYamlCase::Keep, $verdict->case);
    }

    public function testAnUnknownDictSubSettingOrValueNeedsReview(): void
    {
        $verdict = $this->classify($this->player(['game_options' => ['difficulty' => 'nightmare', 'speed' => 'fast', 'color' => 'red']]));

        self::assertEquals([
            new SlotYamlIssue('game_options.difficulty', SlotYamlProblem::UnknownValue, 'nightmare'),
            new SlotYamlIssue('game_options.color', SlotYamlProblem::UnknownSubOption, null),
        ], $verdict->issues);
    }

    public function testAnOptionOfUnknownTypeIsGivenTheBenefitOfTheDoubt(): void
    {
        $verdict = $this->classify($this->player(['progression_balancing' => 'whatever']));

        self::assertSame(SlotYamlCase::Keep, $verdict->case);
    }

    public function testWithoutAnyTypeKnownOnlyRemovedOptionsCount(): void
    {
        $verdict = SlotYamlCompatibility::classify($this->player(['goal' => 'moon', 'removed_later' => 'true']), self::OLD_DEFAULT, self::NEW_DEFAULT, []);

        self::assertEquals([new SlotYamlIssue('removed_later', SlotYamlProblem::RemovedOption, null)], $verdict->issues);
    }

    public function testAnUnreadableYamlNeedsReviewWithoutOptions(): void
    {
        $verdict = SlotYamlCompatibility::unreadable();

        self::assertSame(SlotYamlCase::NeedsReview, $verdict->case);
        self::assertEquals([new SlotYamlIssue('', SlotYamlProblem::Unreadable, null)], $verdict->issues);
    }

    public function testEachIssueReadsAsASentenceForThePlayer(): void
    {
        self::assertSame('« removed_later » n\'existe plus dans cette version.', new SlotYamlIssue('removed_later', SlotYamlProblem::RemovedOption, null)->message());
        self::assertSame('« goal » : la valeur « moon » n\'est plus acceptée.', new SlotYamlIssue('goal', SlotYamlProblem::UnknownValue, 'moon')->message());
        self::assertSame('« starting_level » : 120 est hors des bornes de cette version.', new SlotYamlIssue('starting_level', SlotYamlProblem::OutOfRange, '120')->message());
        self::assertSame('Ton YAML n\'a pas pu être relu : vérifie-le avant la partie.', new SlotYamlIssue('', SlotYamlProblem::Unreadable, null)->message());
    }

    /**
     * @param array<string, mixed> $settings
     *
     * @return array<string, mixed>
     */
    private function player(array $settings): array
    {
        return ['name' => 'Jean', 'game' => 'Crystal Project', 'Crystal Project' => $settings];
    }

    /**
     * @param array<mixed>|null $player
     */
    private function classify(?array $player): \App\GameSelection\Domain\ValueObject\SlotYamlVerdict
    {
        return SlotYamlCompatibility::classify($player, self::OLD_DEFAULT, self::NEW_DEFAULT, self::NEW_TYPES);
    }
}
