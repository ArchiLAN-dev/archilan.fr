<?php

declare(strict_types=1);

namespace App\Tests\Unit\GameSelection;

use App\GameSelection\Application\Support\ApworldIntrospectionNormalizer;
use App\GameSelection\Domain\Entity\Game;
use PHPUnit\Framework\TestCase;

/**
 * Story 9.51: what survives the persistence boundary when an apworld's introspection is stored.
 *
 * `option_types` is a JSON column, so this normalizer is the last thing standing between the runner's
 * answer and a shape the editor will trust. The interesting cases are the drops: a sub-setting left
 * with fewer than two values is discarded rather than stored, because half a vocabulary in a dropdown
 * reads as authoritative while hiding the entries the world actually accepts.
 *
 * Moved here from AdminGameLibraryDictOptionValuesTest by story 38.6, unchanged: the normalizer used to
 * run on upload, it now runs on promotion (see DecideApworldCandidatesTest for that path).
 */
final class ApworldIntrospectionNormalizerTest extends TestCase
{
    public function testDeclaredSubOptionValuesArePersistedBesideTheKeyNames(): void
    {
        self::assertSame([
            'game_options' => [
                'type' => 'dict',
                // `default` is normalized in for every option, dict included; unrelated to this story.
                'default' => null,
                'values' => ['battle_style', 'default_player_name'],
                'keys' => ['battle_style' => ['values' => ['shift', 'set']]],
            ],
        ], ApworldIntrospectionNormalizer::optionTypes([
            'game_options' => [
                'type' => 'dict',
                'values' => ['battle_style', 'default_player_name'],
                'keys' => ['battle_style' => ['values' => ['shift', 'set']]],
            ],
        ]));
    }

    public function testAHalfVocabularyIsDroppedRatherThanStored(): void
    {
        $types = ApworldIntrospectionNormalizer::optionTypes([
            'game_options' => [
                'type' => 'dict',
                'keys' => [
                    'kept' => ['values' => ['a', 'b']],
                    'single' => ['values' => ['only']],
                    'empty' => ['values' => []],
                    'thinned' => ['values' => ['a', 4, null]],
                    'duplicated' => ['values' => ['a', 'a']],
                    'malformed' => ['values' => 'not-a-list'],
                    'missing' => [],
                ],
            ],
        ]);

        self::assertSame(['kept' => ['values' => ['a', 'b']]], $types['game_options']['keys'] ?? null);
    }

    public function testAWorldThatDeclaresNothingStoresNothing(): void
    {
        // Not an empty `keys`: the absence is what tells the editor to keep its free text field.
        $types = ApworldIntrospectionNormalizer::optionTypes([
            'game_options' => ['type' => 'dict', 'values' => ['battle_style']],
        ]);

        self::assertArrayNotHasKey('keys', $types['game_options'] ?? []);
    }

    public function testLocationNamesKeepOnlyNonEmptyStrings(): void
    {
        self::assertSame(['Castle Ramparts Chest', 'Spawn'], ApworldIntrospectionNormalizer::locationNames(['Castle Ramparts Chest', '', 42, null, 'Spawn']));
        self::assertSame([], ApworldIntrospectionNormalizer::locationNames('not-a-list'));
    }

    /** Story 9.58: whatever the orchestrator answers about items reads as names only, or as none. */
    public function testItemsKeepOnlyNames(): void
    {
        self::assertSame(
            ['items' => ['A', 'B'], 'groups' => ['G' => ['A']]],
            ApworldIntrospectionNormalizer::items(['items' => ['A', 1, '', 'B'], 'groups' => ['G' => ['A', 2], 'Empty' => [], '' => ['A'], 'Bad' => 'A']]),
        );
        self::assertSame(['items' => [], 'groups' => []], ApworldIntrospectionNormalizer::items([]));
        self::assertSame(['items' => [], 'groups' => []], ApworldIntrospectionNormalizer::items(null));
    }

    /** Story 9.58: no items known is stored as null, like the locations; groups without items mean nothing. */
    public function testAGameStoresNoItemsAsNull(): void
    {
        $game = Game::create('Hollow Knight', 'hollow-knight', '', null, '', '', 'available', new \DateTimeImmutable());

        $game->recordItemNames([], ['Dreamers' => ['Lurien']]);
        self::assertNull($game->getItemNames());
        self::assertNull($game->getItemGroups());

        $game->recordItemNames(['Lurien'], []);
        self::assertSame(['Lurien'], $game->getItemNames());
        self::assertNull($game->getItemGroups());
    }
}
