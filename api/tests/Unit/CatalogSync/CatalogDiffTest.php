<?php

declare(strict_types=1);

namespace App\Tests\Unit\CatalogSync;

use App\CatalogSync\Application\CatalogSyncService;
use App\CatalogSync\Domain\CatalogEntry;
use App\GameSelection\Domain\ArchipelagoGame;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;

final class CatalogDiffTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeGame(
        string $name,
        string $availability = 'available',
        ?string $catalogSheetName = null,
        ?string $archipelagoGameName = null,
        bool $availabilityLocked = false,
    ): ArchipelagoGame {
        $game = ArchipelagoGame::create(
            $name,
            strtolower(str_replace(' ', '-', $name)),
            'description',
            null,
            'alt',
            '',
            $availability,
            new \DateTimeImmutable(),
        );

        if (null !== $archipelagoGameName) {
            $game->configureApworld('key', 'hash', $archipelagoGameName, 'yaml', new \DateTimeImmutable());
        }

        $game->updateCatalogueMetadata(
            catalogSheetName: $catalogSheetName,
            availabilityLocked: $availabilityLocked,
        );

        return $game;
    }

    private function makeEntry(
        string $name,
        string $availability = 'available',
        bool $bundledWithAp = false,
    ): CatalogEntry {
        return new CatalogEntry(
            name: $name,
            availability: $availability,
            prStatus: null,
            adultContent: false,
            notes: null,
            links: [['label' => $name, 'url' => null]],
            bundledWithAp: $bundledWithAp,
        );
    }

    private function makeService(): CatalogSyncService
    {
        return new CatalogSyncService(
            new MockHttpClient([]),
            new ArrayAdapter(),
            new NullLogger(),
            'sheet-id',
            '',
        );
    }

    // -------------------------------------------------------------------------
    // newGames
    // -------------------------------------------------------------------------

    public function testEntryWithNoMatchGoesToNewGames(): void
    {
        $service = $this->makeService();
        $entry = $this->makeEntry('Hollow Knight');
        $existingGames = [];

        $diff = $service->computeDiff([$entry], $existingGames);

        self::assertCount(1, $diff['newGames']);
        self::assertSame('Hollow Knight', $diff['newGames'][0]->name);
        self::assertCount(0, $diff['stabilityChanged']);
        self::assertCount(0, $diff['removedFromSheet']);
    }

    public function testBundledEntryWithNoMatchGoesToNewGamesWithBundledFlag(): void
    {
        $service = $this->makeService();
        $entry = $this->makeEntry('Clique the Game', bundledWithAp: true);

        $diff = $service->computeDiff([$entry], []);

        self::assertCount(1, $diff['newGames']);
        self::assertTrue($diff['newGames'][0]->bundledWithAp);
    }

    // -------------------------------------------------------------------------
    // Matching - priority order
    // -------------------------------------------------------------------------

    public function testCatalogSheetNameExactMatchPreventsNewGame(): void
    {
        $service = $this->makeService();
        $game = $this->makeGame('Hollow Knight', catalogSheetName: 'Hollow Knight');
        $entry = $this->makeEntry('Hollow Knight');

        $diff = $service->computeDiff([$entry], [$game]);

        self::assertCount(0, $diff['newGames']);
    }

    public function testArchipelagoGameNameCaseInsensitiveMatch(): void
    {
        $service = $this->makeService();
        $game = $this->makeGame('HK', archipelagoGameName: 'hollow knight');
        $entry = $this->makeEntry('Hollow Knight'); // different case

        $diff = $service->computeDiff([$entry], [$game]);

        self::assertCount(0, $diff['newGames']);
    }

    public function testNameCaseInsensitiveFallbackMatch(): void
    {
        $service = $this->makeService();
        $game = $this->makeGame('hollow knight'); // lowercase in DB
        $entry = $this->makeEntry('Hollow Knight'); // capitalized in sheet

        $diff = $service->computeDiff([$entry], [$game]);

        self::assertCount(0, $diff['newGames']);
    }

    /**
     * When one game has catalogSheetName matching the entry and another game's
     * name would also match, catalogSheetName wins.
     */
    public function testCatalogSheetNameTakesPriorityOverNameMatch(): void
    {
        $service = $this->makeService();

        // Game A: matched by catalogSheetName
        $gameA = $this->makeGame('Hollow Knight Silksong', catalogSheetName: 'Hollow Knight');
        // Game B: would match by name - but step 1 already found gameA
        $gameB = $this->makeGame('Hollow Knight');

        $entry = $this->makeEntry('Hollow Knight');

        $diff = $service->computeDiff([$entry], [$gameA, $gameB]);

        // Entry matched gameA via catalogSheetName, nothing is new
        self::assertCount(0, $diff['newGames']);
        // gameA (availability=available) vs entry (available) → no change
        self::assertCount(0, $diff['stabilityChanged']);
    }

    // -------------------------------------------------------------------------
    // stabilityChanged
    // -------------------------------------------------------------------------

    public function testStabilityChangedWhenAvailabilityDiffers(): void
    {
        $service = $this->makeService();
        $game = $this->makeGame('Hollow Knight', 'available');
        $entry = $this->makeEntry('Hollow Knight', 'experimental');

        $diff = $service->computeDiff([$entry], [$game]);

        self::assertCount(1, $diff['stabilityChanged']);
        self::assertSame($game, $diff['stabilityChanged'][0]['game']);
        self::assertSame($entry, $diff['stabilityChanged'][0]['entry']);
        self::assertCount(0, $diff['newGames']);
    }

    public function testAvailabilityLockedGameExcludedFromStabilityChanged(): void
    {
        $service = $this->makeService();
        $game = $this->makeGame('Hollow Knight', 'available', availabilityLocked: true);
        $entry = $this->makeEntry('Hollow Knight', 'experimental'); // stability changed

        $diff = $service->computeDiff([$entry], [$game]);

        self::assertCount(0, $diff['stabilityChanged']); // locked → excluded
        self::assertCount(0, $diff['newGames']); // still matched
    }

    public function testNoStabilityChangeWhenAvailabilityMatches(): void
    {
        $service = $this->makeService();
        $game = $this->makeGame('Hollow Knight', 'available');
        $entry = $this->makeEntry('Hollow Knight', 'available');

        $diff = $service->computeDiff([$entry], [$game]);

        self::assertCount(0, $diff['stabilityChanged']);
        self::assertCount(0, $diff['newGames']);
    }

    // -------------------------------------------------------------------------
    // removedFromSheet
    // -------------------------------------------------------------------------

    public function testRemovedFromSheetWhenCatalogSheetNameSetAndAbsent(): void
    {
        $service = $this->makeService();
        $game = $this->makeGame('Hollow Knight', catalogSheetName: 'Hollow Knight');
        $entries = []; // game no longer in sheet

        $diff = $service->computeDiff($entries, [$game]);

        self::assertCount(1, $diff['removedFromSheet']);
        self::assertSame($game, $diff['removedFromSheet'][0]);
    }

    public function testGameWithoutCatalogSheetNameNotInRemovedFromSheet(): void
    {
        $service = $this->makeService();
        $game = $this->makeGame('Hollow Knight'); // no catalogSheetName
        $entries = [];

        $diff = $service->computeDiff($entries, [$game]);

        self::assertCount(0, $diff['removedFromSheet']);
    }

    public function testMatchedGameWithCatalogSheetNameNotInRemovedFromSheet(): void
    {
        $service = $this->makeService();
        $game = $this->makeGame('Hollow Knight', catalogSheetName: 'Hollow Knight');
        $entry = $this->makeEntry('Hollow Knight'); // still present in sheet

        $diff = $service->computeDiff([$entry], [$game]);

        self::assertCount(0, $diff['removedFromSheet']); // present → not removed
    }

    // -------------------------------------------------------------------------
    // Multiple games / complex scenarios
    // -------------------------------------------------------------------------

    public function testFullDiffWithMixedScenarios(): void
    {
        $service = $this->makeService();

        $gameTracked = $this->makeGame('Celeste', 'available', catalogSheetName: 'Celeste');
        $gameUntracked = $this->makeGame('Hollow Knight', 'available'); // no catalogSheetName
        $gameLocked = $this->makeGame('Dark Souls', 'available', availabilityLocked: true);
        $gameRemoved = $this->makeGame('Removed Game', 'available', catalogSheetName: 'Removed Game');

        $sheetEntries = [
            $this->makeEntry('Celeste', 'experimental'), // stability changed
            $this->makeEntry('Hollow Knight', 'available'), // matched by name, no change
            $this->makeEntry('Dark Souls', 'experimental'), // locked, no stability change
            $this->makeEntry('Brand New Game'), // new
        ];
        // 'Removed Game' is not in sheetEntries

        $diff = $service->computeDiff($sheetEntries, [$gameTracked, $gameUntracked, $gameLocked, $gameRemoved]);

        self::assertCount(1, $diff['newGames']);
        self::assertSame('Brand New Game', $diff['newGames'][0]->name);

        self::assertCount(1, $diff['stabilityChanged']);
        self::assertSame($gameTracked, $diff['stabilityChanged'][0]['game']);

        self::assertCount(1, $diff['removedFromSheet']);
        self::assertSame($gameRemoved, $diff['removedFromSheet'][0]);
    }
}
