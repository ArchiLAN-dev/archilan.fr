<?php

declare(strict_types=1);

namespace App\Tests\Unit\GameSelection;

use App\CatalogSync\Application\Service\ApworldVersionChecker;
use App\GameSelection\Application\Port\GameCatalogLinksProviderInterface;
use App\GameSelection\Application\Port\GameUsageCounterInterface;
use App\GameSelection\Application\Port\IgdbHttpClientInterface;
use App\GameSelection\Application\Query\AdminGameListQueryInterface;
use App\GameSelection\Application\Service\AdminGameLibrary;
use App\GameSelection\Application\Support\GamePlatformResolver;
use App\GameSelection\Application\Support\GameTutorialSeeder;
use App\GameSelection\Application\Support\InstallStepsNormalizer;
use App\GameSelection\Application\Support\InstallStepsReader;
use App\GameSelection\Domain\Entity\Game;
use App\GameSelection\Domain\Repository\GameRepositoryInterface;
use App\Sessions\Application\Port\RunnerGatewayInterface;
use App\Shared\Infrastructure\Adapter\MinioStorageInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * Story 9.51: what survives the persistence boundary when an apworld is uploaded.
 *
 * `option_types` is a JSON column, so this normalizer is the last thing standing between the runner's
 * answer and a shape the editor will trust. The interesting cases are the drops: a sub-setting left
 * with fewer than two values is discarded rather than stored, because half a vocabulary in a dropdown
 * reads as authoritative while hiding the entries the world actually accepts.
 */
final class AdminGameLibraryDictOptionValuesTest extends TestCase
{
    public function testDeclaredSubOptionValuesArePersistedBesideTheKeyNames(): void
    {
        $game = $this->game();

        $this->library($game, $this->runnerReturning([
            'game_options' => [
                'type' => 'dict',
                'values' => ['battle_style', 'default_player_name'],
                'keys' => ['battle_style' => ['values' => ['shift', 'set']]],
            ],
        ]))->configureApworld($game->getId(), 'binary', 'game.apworld');

        self::assertSame([
            'game_options' => [
                'type' => 'dict',
                // `default` is normalized in for every option, dict included; unrelated to this story.
                'default' => null,
                'values' => ['battle_style', 'default_player_name'],
                'keys' => ['battle_style' => ['values' => ['shift', 'set']]],
            ],
        ], $game->getOptionTypes());
    }

    public function testAHalfVocabularyIsDroppedRatherThanStored(): void
    {
        $game = $this->game();

        $this->library($game, $this->runnerReturning([
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
        ]))->configureApworld($game->getId(), 'binary', 'game.apworld');

        $types = $game->getOptionTypes() ?? [];
        self::assertSame(['kept' => ['values' => ['a', 'b']]], $types['game_options']['keys'] ?? null);
    }

    public function testAWorldThatDeclaresNothingStoresNothing(): void
    {
        // Not an empty `keys`: the absence is what tells the editor to keep its free text field.
        $game = $this->game();

        $this->library($game, $this->runnerReturning([
            'game_options' => ['type' => 'dict', 'values' => ['battle_style']],
        ]))->configureApworld($game->getId(), 'binary', 'game.apworld');

        self::assertArrayNotHasKey('keys', ($game->getOptionTypes() ?? [])['game_options'] ?? []);
    }

    // ── Story 9.52: what an admin declares when the apworld declares nothing ──

    public function testAnAdminCurationIsStoredApartAndSurfacesInTheEffectiveTable(): void
    {
        $game = $this->game();
        $game->recordOptionTypes(['game_options' => ['type' => 'dict', 'values' => ['battle_style']]]);

        $result = $this->library($game, self::createStub(RunnerGatewayInterface::class))
            ->saveDictOptionValues($game->getId(), 'game_options', [
                'battle_style' => ['values' => ['shift', 'set'], 'closed' => true],
            ]);

        self::assertSame([], $result['errors']);
        // Stored on its own, so the next re-introspection cannot erase it...
        self::assertSame(
            ['battle_style' => ['values' => ['shift', 'set'], 'closed' => true]],
            $game->getDictOptionValues()['game_options'] ?? null,
        );
        // ...and merged for everyone who reads the table.
        self::assertSame(
            ['values' => ['shift', 'set'], 'closed' => true],
            $game->getEffectiveOptionTypes()['game_options']['keys']['battle_style'] ?? null,
        );
    }

    public function testASingleValueIsRefused(): void
    {
        // A dropdown with one entry offers the player nothing to choose; it reads as a bug rather
        // than as a curation.
        $game = $this->game();

        $result = $this->library($game, self::createStub(RunnerGatewayInterface::class))
            ->saveDictOptionValues($game->getId(), 'game_options', [
                'battle_style' => ['values' => ['shift'], 'closed' => false],
            ]);

        self::assertArrayHasKey('values', $result['errors']);
        self::assertNull($game->getDictOptionValues());
    }

    public function testValuesAreTrimmedAndDeduplicated(): void
    {
        $game = $this->game();
        $game->recordOptionTypes(['game_options' => ['type' => 'dict']]);

        $this->library($game, self::createStub(RunnerGatewayInterface::class))
            ->saveDictOptionValues($game->getId(), 'game_options', [
                'battle_style' => ['values' => ['  shift ', 'set', 'shift', '   '], 'closed' => false],
            ]);

        self::assertSame(
            ['values' => ['shift', 'set'], 'closed' => false],
            $game->getDictOptionValues()['game_options']['battle_style'] ?? null,
        );
    }

    public function testAnEmptyMapHandsTheOptionBackToIntrospection(): void
    {
        $game = $this->game();
        $game->recordOptionTypes(['game_options' => ['type' => 'dict']]);
        $now = new \DateTimeImmutable('2026-08-28T10:00:00+00:00');
        $game->overrideDictOptionValues('game_options', ['k' => ['values' => ['a', 'b'], 'closed' => true]], $now);

        $result = $this->library($game, self::createStub(RunnerGatewayInterface::class))
            ->saveDictOptionValues($game->getId(), 'game_options', []);

        self::assertSame([], $result['errors']);
        self::assertNull($game->getDictOptionValues());
    }

    public function testAnUnknownGameIsReportedAsNotFound(): void
    {
        $repository = self::createStub(GameRepositoryInterface::class);
        $repository->method('findById')->willReturn(null);

        $game = $this->game();
        $result = $this->libraryWith($repository, self::createStub(RunnerGatewayInterface::class))
            ->saveDictOptionValues('missing', 'game_options', []);

        self::assertFalse($result['found']);
        self::assertNull($game->getDictOptionValues());
    }

    /** @param array<string, mixed> $optionTypes */
    private function runnerReturning(array $optionTypes): RunnerGatewayInterface
    {
        $runner = self::createStub(RunnerGatewayInterface::class);
        $runner->method('uploadApworld')->willReturn([
            'storageKey' => 'deadbeef.apworld',
            'hash' => 'deadbeef',
            'archipelagoGameName' => 'Pokemon Platinum',
            'defaultYaml' => "game: Pokemon Platinum\n",
            'optionTypes' => $optionTypes,
            'locationNames' => [],
        ]);

        return $runner;
    }

    private function game(): Game
    {
        return Game::create(
            'Pokemon Platinum', 'pokemon-platinum', 'desc', null, 'alt', '',
            Game::AVAILABILITY_AVAILABLE, new \DateTimeImmutable('2026-08-28T10:00:00+00:00'),
        );
    }

    private function library(Game $game, RunnerGatewayInterface $runner): AdminGameLibrary
    {
        $repository = self::createStub(GameRepositoryInterface::class);
        $repository->method('findById')->willReturn($game);

        return $this->libraryWith($repository, $runner);
    }

    private function libraryWith(GameRepositoryInterface $repository, RunnerGatewayInterface $runner): AdminGameLibrary
    {
        $usage = self::createStub(GameUsageCounterInterface::class);
        $usage->method('count')->willReturn(0);

        $storage = self::createStub(MinioStorageInterface::class);
        $storage->method('exists')->willReturn(true);

        $normalizer = new InstallStepsNormalizer();

        return new AdminGameLibrary(
            $repository,
            self::createStub(AdminGameListQueryInterface::class),
            new NullLogger(),
            $runner,
            $storage,
            new MockClock(),
            'apworlds',
            new ApworldVersionChecker(new MockHttpClient([]), new NullLogger(), 'token'),
            $usage,
            new GamePlatformResolver(self::createStub(IgdbHttpClientInterface::class), new NullLogger()),
            $normalizer,
            new GameTutorialSeeder(self::createStub(GameCatalogLinksProviderInterface::class), $normalizer),
            new InstallStepsReader(),
        );
    }
}
