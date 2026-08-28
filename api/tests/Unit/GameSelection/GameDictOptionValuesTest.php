<?php

declare(strict_types=1);

namespace App\Tests\Unit\GameSelection;

use App\GameSelection\Domain\Entity\Game;
use PHPUnit\Framework\TestCase;

/**
 * Story 9.52: what an admin declares about a dict option, laid over what the apworld said.
 *
 * The point of keeping the two apart is that they answer different questions. Introspection says
 * what the world declares; the curation says what we decided because the world declares nothing.
 * Merging them at write time would lose that distinction at the first re-introspection - which is
 * exactly what these tests pin down.
 */
final class GameDictOptionValuesTest extends TestCase
{
    private const string NOW = '2026-08-28T10:00:00+00:00';

    public function testTheCurationIsLaidOverIntrospectionPerSubSetting(): void
    {
        $game = $this->game();
        $game->recordOptionTypes([
            'game_options' => [
                'type' => 'dict',
                'keys' => ['text_speed' => ['values' => ['mid', 'fast']]],
            ],
        ]);

        $game->overrideDictOptionValues('game_options', [
            'battle_style' => ['values' => ['shift', 'set'], 'closed' => true],
        ], $this->now());

        // Curating one sub-setting must not hide what introspection knew about its neighbour.
        self::assertSame([
            'text_speed' => ['values' => ['mid', 'fast']],
            'battle_style' => ['values' => ['shift', 'set'], 'closed' => true],
        ], $game->getEffectiveOptionTypes()['game_options']['keys'] ?? null);
    }

    public function testTheCurationWinsOverIntrospectionOnTheSameSubSetting(): void
    {
        // A human who took the trouble to write the list has seen the world; the schema may be
        // right and still incomplete.
        $game = $this->game();
        $game->recordOptionTypes([
            'game_options' => ['type' => 'dict', 'keys' => ['sound' => ['values' => ['stereo', 'mono']]]],
        ]);

        $game->overrideDictOptionValues('game_options', [
            'sound' => ['values' => ['stereo', 'mono', 'surround'], 'closed' => false],
        ], $this->now());

        self::assertSame(
            ['values' => ['stereo', 'mono', 'surround'], 'closed' => false],
            $game->getEffectiveOptionTypes()['game_options']['keys']['sound'] ?? null,
        );
    }

    public function testAReintrospectionDoesNotErasTheCuration(): void
    {
        // The whole reason the curation lives in its own column: recordOptionTypes() replaces the
        // introspected table wholesale, at every upload and every backfill.
        $game = $this->game();
        $game->overrideDictOptionValues('game_options', [
            'battle_style' => ['values' => ['shift', 'set'], 'closed' => true],
        ], $this->now());

        $game->recordOptionTypes(['game_options' => ['type' => 'dict', 'values' => ['battle_style']]]);

        self::assertSame(
            ['values' => ['shift', 'set'], 'closed' => true],
            $game->getEffectiveOptionTypes()['game_options']['keys']['battle_style'] ?? null,
        );
    }

    public function testClearingHandsTheOptionBackToIntrospection(): void
    {
        $game = $this->game();
        $game->recordOptionTypes([
            'game_options' => ['type' => 'dict', 'keys' => ['sound' => ['values' => ['stereo', 'mono']]]],
        ]);
        $game->overrideDictOptionValues('game_options', [
            'sound' => ['values' => ['a', 'b'], 'closed' => true],
        ], $this->now());

        $game->overrideDictOptionValues('game_options', null, $this->now());

        self::assertNull($game->getDictOptionValues());
        self::assertSame(
            ['values' => ['stereo', 'mono']],
            $game->getEffectiveOptionTypes()['game_options']['keys']['sound'] ?? null,
        );
    }

    public function testCuratingOneOptionLeavesTheOthersAlone(): void
    {
        $game = $this->game();
        $game->overrideDictOptionValues('game_options', [
            'a' => ['values' => ['1', '2'], 'closed' => false],
        ], $this->now());
        $game->overrideDictOptionValues('advanced_characters', [
            'b' => ['values' => ['3', '4'], 'closed' => false],
        ], $this->now());

        $game->overrideDictOptionValues('game_options', null, $this->now());

        self::assertTrue($game->hasDictOptionValues('advanced_characters'));
        self::assertFalse($game->hasDictOptionValues('game_options'));
    }

    public function testACurationForAnOptionIntrospectionNeverDescribedIsIgnored(): void
    {
        // There is no entry to lay it over, and inventing one would assert on the admin's word
        // that the option is a dict at all.
        $game = $this->game();
        $game->recordOptionTypes(['goal' => ['type' => 'choice']]);
        $game->overrideDictOptionValues('unknown_option', [
            'x' => ['values' => ['1', '2'], 'closed' => false],
        ], $this->now());

        self::assertArrayNotHasKey('unknown_option', $game->getEffectiveOptionTypes() ?? []);
    }

    public function testWithoutIntrospectionThereIsNothingToLayItOver(): void
    {
        $game = $this->game();
        $game->overrideDictOptionValues('game_options', [
            'x' => ['values' => ['1', '2'], 'closed' => false],
        ], $this->now());

        self::assertNull($game->getEffectiveOptionTypes());
    }

    public function testWithoutCurationTheTableIsTheIntrospectedOneUntouched(): void
    {
        $game = $this->game();
        $types = ['game_options' => ['type' => 'dict', 'values' => ['battle_style']]];
        $game->recordOptionTypes($types);

        self::assertSame($types, $game->getEffectiveOptionTypes());
    }

    private function game(): Game
    {
        return Game::create(
            'Pokemon Platinum', 'pokemon-platinum', 'desc', null, 'alt', '',
            Game::AVAILABILITY_AVAILABLE, new \DateTimeImmutable(self::NOW),
        );
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW);
    }
}
