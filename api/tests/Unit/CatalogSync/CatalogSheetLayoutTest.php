<?php

declare(strict_types=1);

namespace App\Tests\Unit\CatalogSync;

use App\CatalogSync\Application\Service\CatalogSyncService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Story 14.11: the catalogue sheet is read by its column headers, not by position. The layout below is the
 * real "Archipelago Games Sheet" as of 2026-09-26: a banner row, the header on row 2, "18+ / Unrated" before
 * "Links & Downloads", and "Notes" in the ninth column.
 */
final class CatalogSheetLayoutTest extends TestCase
{
    private const array CURRENT_HEADER = ['Game', 'Stability', 'PR Status', '18+ / Unrated', 'Links & Downloads', 'Setup Guides', 'Support', 'Disclosures', 'Notes'];

    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new RecordingLogger();
    }

    public function testTheCurrentLayoutIsReadByItsHeadersThroughTheApi(): void
    {
        $entries = $this->viaApi(
            main: [
                $this->row(['Hover over column headers for more details!', 'If something is missing, leave a comment']),
                $this->row(self::CURRENT_HEADER),
                [
                    'values' => [
                        $this->cell('Crystal Project'),
                        $this->cell('Unstable'),
                        $this->cell('--'),
                        ['userEnteredValue' => ['boolValue' => false]],
                        ['userEnteredValue' => ['stringValue' => 'Github Releases'], 'userEnteredFormat' => ['textFormat' => ['link' => ['uri' => 'https://github.com/Emerassi/CrystalProjectAPWorld/releases/latest']]]],
                        $this->cell('Website'),
                        $this->cell('Thread'),
                        $this->cell('Unknown'),
                        $this->cell('Game is fully playable with a built-in tracker.'),
                    ],
                ],
                [
                    'values' => [
                        $this->cell('Some Adult Game'),
                        $this->cell('Stable'),
                        $this->cell('Core'),
                        ['userEnteredValue' => ['boolValue' => true]],
                        [
                            'userEnteredValue' => ['stringValue' => 'APWorld (Beta), Core PR'],
                            'textFormatRuns' => [
                                ['startIndex' => 0, 'format' => ['link' => ['uri' => 'https://github.com/qwint/Archipelago/releases']]],
                                ['startIndex' => 14, 'format' => []],
                                ['startIndex' => 16, 'format' => ['link' => ['uri' => 'https://github.com/ArchipelagoMW/Archipelago/pull/6217']]],
                            ],
                        ],
                    ],
                ],
            ],
            bundled: [
                $this->row(["This is a duplication of the main game list, for convienience.\nThese games' worlds are included with the main Archipelago installation."]),
                $this->row(['Game', 'Game Page', 'Setup Guide']),
                $this->row(['Adventure', 'Game Page', 'Setup Guide']),
            ],
        );

        self::assertSame(['Crystal Project', 'Some Adult Game', 'Adventure'], array_map(static fn ($e): string => $e->name, $entries), 'neither a banner nor a header is a game');

        $crystal = $entries[0];
        self::assertSame('experimental', $crystal->availability);
        self::assertSame([['label' => 'Github Releases', 'url' => 'https://github.com/Emerassi/CrystalProjectAPWorld/releases/latest']], $crystal->links);
        self::assertFalse($crystal->adultContent);
        self::assertSame('Game is fully playable with a built-in tracker.', $crystal->notes);

        $adult = $entries[1];
        self::assertTrue($adult->adultContent);
        self::assertSame('https://github.com/qwint/Archipelago/releases', $adult->links[0]['url']);
        self::assertSame('https://github.com/ArchipelagoMW/Archipelago/pull/6217', $adult->links[1]['url']);
        self::assertNull($adult->notes);

        self::assertTrue($entries[2]->bundledWithAp);
    }

    public function testTheCurrentLayoutIsReadByItsHeadersThroughTheCsvExport(): void
    {
        // Without an API key the export gives text only: the labels, never the URLs.
        $main = implode("\n", [
            '"Hover over column headers for more details!","If something is missing, leave a comment"',
            implode(',', self::CURRENT_HEADER),
            'Crystal Project,Unstable,--,FALSE,Github Releases,Website,Thread,Unknown,Game is fully playable.',
            'Some Adult Game,Stable,Core,TRUE,APWorld,,,,',
        ]);
        $bundled = implode("\n", [
            '"This is a duplication of the main game list.'."\n".'These games\' worlds are included."',
            'Game,Game Page,Setup Guide',
            'Adventure,Game Page,Setup Guide',
        ]);

        $entries = $this->service(new MockHttpClient([new MockResponse($main), new MockResponse($bundled)]), '')->fetchSheet();

        self::assertSame(['Crystal Project', 'Some Adult Game', 'Adventure'], array_map(static fn ($e): string => $e->name, $entries));
        self::assertSame([['label' => 'Github Releases', 'url' => null]], $entries[0]->links);
        self::assertFalse($entries[0]->adultContent);
        self::assertSame('Game is fully playable.', $entries[0]->notes);
        self::assertTrue($entries[1]->adultContent);
    }

    public function testColumnsAreFoundWhereverTheyAre(): void
    {
        $entries = $this->viaApi(main: [
            $this->row(['Notes', 'Links & Downloads', '18+ / Unrated', 'Stability', 'Game']),
            [
                'values' => [
                    $this->cell('A note'),
                    ['userEnteredValue' => ['stringValue' => 'Releases'], 'userEnteredFormat' => ['textFormat' => ['link' => ['uri' => 'https://github.com/owner/repo/releases']]]],
                    $this->cell('Yes'),
                    $this->cell('Stable'),
                    $this->cell('Moved Game'),
                ],
            ],
        ]);

        self::assertCount(1, $entries);
        self::assertSame('Moved Game', $entries[0]->name);
        self::assertSame('https://github.com/owner/repo/releases', $entries[0]->links[0]['url']);
        self::assertTrue($entries[0]->adultContent);
        self::assertSame('A note', $entries[0]->notes);
    }

    public function testAMissingRequiredColumnSkipsTheTabAndSaysSo(): void
    {
        // Never a value read from the wrong column: without "Stability", nothing can be classified.
        $entries = $this->viaApi(main: [
            $this->row(['Game', 'Links & Downloads']),
            $this->row(['Some Game', 'Github']),
        ]);

        self::assertSame([], $entries);
        self::assertContains(['level' => 'error', 'message' => 'catalog_sync.sheet_header_missing'], $this->logger->logs);
    }

    public function testAMissingOptionalColumnGivesAnEmptyValueAndAWarning(): void
    {
        $entries = $this->viaApi(main: [
            $this->row(['Game', 'Stability']),
            $this->row(['Some Game', 'Stable']),
        ]);

        self::assertCount(1, $entries);
        self::assertSame([], $entries[0]->links);
        self::assertFalse($entries[0]->adultContent);
        self::assertNull($entries[0]->notes);
        self::assertContains(['level' => 'warning', 'message' => 'catalog_sync.sheet_column_missing'], $this->logger->logs);
    }

    /**
     * @param list<array<string, mixed>> $main
     * @param list<array<string, mixed>> $bundled
     *
     * @return list<\App\CatalogSync\Domain\ValueObject\CatalogEntry>
     */
    private function viaApi(array $main, array $bundled = []): array
    {
        $payload = ['sheets' => [
            ['properties' => ['sheetId' => 58422002], 'data' => [['rowData' => $main]]],
            ['properties' => ['sheetId' => 1675722515], 'data' => [['rowData' => $bundled]]],
        ]];

        return $this->service(new MockHttpClient([new MockResponse(json_encode($payload) ?: '')]), 'api-key')->fetchSheet();
    }

    private function service(MockHttpClient $http, string $apiKey): CatalogSyncService
    {
        return new CatalogSyncService($http, new ArrayAdapter(), $this->logger, new MockClock(), 'sheet-id', $apiKey);
    }

    /**
     * @param list<string> $texts
     *
     * @return array{values: list<array<string, mixed>>}
     */
    private function row(array $texts): array
    {
        return ['values' => array_map($this->cell(...), $texts)];
    }

    /**
     * @return array<string, mixed>
     */
    private function cell(string $text): array
    {
        return ['userEnteredValue' => ['stringValue' => $text]];
    }
}
