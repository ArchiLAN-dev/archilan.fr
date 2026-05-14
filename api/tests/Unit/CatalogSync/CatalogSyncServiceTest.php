<?php

declare(strict_types=1);

namespace App\Tests\Unit\CatalogSync;

use App\CatalogSync\Application\CatalogSyncService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class CatalogSyncServiceTest extends TestCase
{
    private const MAIN_CSV = <<<CSV
        Name,Stability,PR Status,Links & Downloads,18+ / Unrated,Notes
        Hollow Knight,Stable,,Github Releases,No,Fast platformer
        Celeste,Stable,PR #123,,No,
        The Binding of Isaac,Unstable,,,No,
        Dark Souls,Broken on Main,,,No,Very hard
        Super Mario RPG,Stable,,,Yes,
        Metroid Dread,Stable,,,No,
        Pokemon Red,Unstable,,,No,
        Hades,Stable,,,No,Roguelike
        Slay the Spire,Stable,PR #456,,No,
        Minecraft,Stable,,,No,
        CSV;

    private const BUNDLED_CSV = <<<CSV
        Name
        Clique the Game
        A Link to the Past
        Super Metroid
        CSV;

    public function testFetchSheetViaCsvReturnsTypedEntries(): void
    {
        $http = new MockHttpClient([
            new MockResponse(self::MAIN_CSV),
            new MockResponse(self::BUNDLED_CSV),
        ]);

        $service = $this->makeService($http, '');

        $entries = $service->fetchSheet();

        self::assertCount(13, $entries);

        $hollow = $entries[0];
        self::assertSame('Hollow Knight', $hollow->name);
        self::assertSame('available', $hollow->availability);
        self::assertNull($hollow->prStatus);
        self::assertFalse($hollow->adultContent);
        self::assertSame('Fast platformer', $hollow->notes);
        self::assertSame([['label' => 'Github Releases', 'url' => null]], $hollow->links);
        self::assertFalse($hollow->bundledWithAp);

        $celeste = $entries[1];
        self::assertSame('PR #123', $celeste->prStatus);
        self::assertNull($celeste->notes);
        self::assertSame([], $celeste->links);

        $binding = $entries[2];
        self::assertSame('experimental', $binding->availability);

        $darkSouls = $entries[3];
        self::assertSame('unavailable', $darkSouls->availability);

        $mario = $entries[4];
        self::assertTrue($mario->adultContent);

        $slayTheSpire = $entries[8];
        self::assertSame('PR #456', $slayTheSpire->prStatus);

        $clique = $entries[10];
        self::assertSame('Clique the Game', $clique->name);
        self::assertSame('available', $clique->availability);
        self::assertTrue($clique->bundledWithAp);
        self::assertSame([], $clique->links);
        self::assertNull($clique->prStatus);
        self::assertFalse($clique->adultContent);
    }

    public function testFetchSheetViaCsvLogsWarningWhenApiKeyMissing(): void
    {
        $http = new MockHttpClient([
            new MockResponse(self::MAIN_CSV),
            new MockResponse(self::BUNDLED_CSV),
        ]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(self::stringContains('api_key_missing'));

        $service = new CatalogSyncService($http, new ArrayAdapter(), $logger, 'sheet-id', '');
        $service->fetchSheet();
    }

    public function testFetchSheetViaApiExtractsHyperlinksFromTextFormatRuns(): void
    {
        $apiPayload = [
            'sheets' => [
                [
                    'properties' => ['sheetId' => 58422002],
                    'data' => [
                        [
                            'rowData' => [
                                // header row (skipped)
                                ['values' => [
                                    ['userEnteredValue' => ['stringValue' => 'Game']],
                                    ['userEnteredValue' => ['stringValue' => 'Stability']],
                                    ['userEnteredValue' => ['stringValue' => 'PR Status']],
                                    ['userEnteredValue' => ['stringValue' => 'Links & Downloads']],
                                    ['userEnteredValue' => ['stringValue' => '18+ / Unrated']],
                                    ['userEnteredValue' => ['stringValue' => 'Notes']],
                                ]],
                                // data row: links with textFormatRuns in col 3
                                ['values' => [
                                    ['userEnteredValue' => ['stringValue' => 'Hollow Knight']],
                                    ['userEnteredValue' => ['stringValue' => 'Stable']],
                                    ['userEnteredValue' => ['stringValue' => '']],
                                    [
                                        'userEnteredValue' => ['stringValue' => 'Github Releases'],
                                        'textFormatRuns' => [
                                            ['startIndex' => 0, 'format' => ['link' => ['uri' => 'https://github.com/alwaysintreble/Archipelago/releases']]],
                                        ],
                                    ],
                                    ['userEnteredValue' => ['stringValue' => 'No']],
                                    ['userEnteredValue' => ['stringValue' => 'Great game']],
                                ]],
                                // data row without hyperlink in col 3
                                ['values' => [
                                    ['userEnteredValue' => ['stringValue' => 'Celeste']],
                                    ['userEnteredValue' => ['stringValue' => 'Unstable']],
                                    ['userEnteredValue' => ['stringValue' => 'PR #99']],
                                    ['userEnteredValue' => ['stringValue' => '']],
                                    ['userEnteredValue' => ['stringValue' => '']],
                                    ['userEnteredValue' => ['stringValue' => '']],
                                ]],
                            ],
                        ],
                    ],
                ],
                [
                    'properties' => ['sheetId' => 1675722515],
                    'data' => [
                        [
                            'rowData' => [
                                // header row (skipped)
                                ['values' => [['userEnteredValue' => ['stringValue' => 'Name']]]],
                                // bundled game
                                ['values' => [['userEnteredValue' => ['stringValue' => 'Clique the Game']]]],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $http = new MockHttpClient([
            new MockResponse(json_encode($apiPayload) ?: ''),
        ]);

        $service = $this->makeService($http, 'fake-api-key');
        $entries = $service->fetchSheet();

        self::assertCount(3, $entries);

        $hollow = $entries[0];
        self::assertSame('Hollow Knight', $hollow->name);
        self::assertSame('available', $hollow->availability);
        self::assertSame('Github Releases', $hollow->links[0]['label']);
        self::assertSame('https://github.com/alwaysintreble/Archipelago/releases', $hollow->links[0]['url']);
        self::assertSame('Great game', $hollow->notes);
        self::assertFalse($hollow->bundledWithAp);

        $celeste = $entries[1];
        self::assertSame('Celeste', $celeste->name);
        self::assertSame('experimental', $celeste->availability);
        self::assertSame('PR #99', $celeste->prStatus);
        self::assertSame([], $celeste->links); // empty col 3, no links
        self::assertFalse($celeste->bundledWithAp);

        $clique = $entries[2];
        self::assertSame('Clique the Game', $clique->name);
        self::assertSame('available', $clique->availability);
        self::assertTrue($clique->bundledWithAp);
        self::assertSame([], $clique->links);
    }

    public function testFetchSheetResultIsCached(): void
    {
        $http = new MockHttpClient([
            new MockResponse(self::MAIN_CSV),
            new MockResponse(self::BUNDLED_CSV),
        ]);

        $service = $this->makeService($http, '');
        $service->fetchSheet();
        $service->fetchSheet(); // second call should not trigger HTTP requests

        self::assertSame(2, $http->getRequestsCount()); // only 2 total (main + bundled)
    }

    public function testFetchSheetViaApiExtractsCellLevelHyperlinkFromUserEnteredFormat(): void
    {
        $apiPayload = [
            'sheets' => [
                [
                    'properties' => ['sheetId' => 58422002],
                    'data' => [
                        [
                            'rowData' => [
                                ['values' => [['userEnteredValue' => ['stringValue' => 'Game']]]],
                                ['values' => [
                                    // col 0: name (plain text)
                                    ['userEnteredValue' => ['stringValue' => 'Hollow Knight']],
                                    // col 1: stability
                                    ['userEnteredValue' => ['stringValue' => 'Stable']],
                                    // col 2: PR status (empty)
                                    ['userEnteredValue' => ['stringValue' => '']],
                                    // col 3: Links cell with cell-level hyperlink (single link, no textFormatRuns)
                                    [
                                        'userEnteredValue' => ['stringValue' => 'Github Releases'],
                                        'userEnteredFormat' => [
                                            'textFormat' => [
                                                'link' => ['uri' => 'https://github.com/alwaysintreble/HollowKnight'],
                                            ],
                                        ],
                                    ],
                                ]],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $http = new MockHttpClient([new MockResponse(json_encode($apiPayload) ?: '')]);
        $entries = $this->makeService($http, 'api-key')->fetchSheet();

        self::assertCount(1, $entries);
        self::assertSame('Github Releases', $entries[0]->links[0]['label']);
        self::assertSame('https://github.com/alwaysintreble/HollowKnight', $entries[0]->links[0]['url']);
    }

    public function testSheetSheetsOtherThanMainAndBundledAreIgnored(): void
    {
        $apiPayload = [
            'sheets' => [
                [
                    'properties' => ['sheetId' => 99999999], // unknown sheet
                    'data' => [['rowData' => [
                        ['values' => [['userEnteredValue' => ['stringValue' => 'Row 1']]]],
                    ]]],
                ],
                [
                    'properties' => ['sheetId' => 58422002],
                    'data' => [['rowData' => [
                        ['values' => [['userEnteredValue' => ['stringValue' => 'Name']]]],
                        ['values' => [
                            ['userEnteredValue' => ['stringValue' => 'Hollow Knight']],
                            ['userEnteredValue' => ['stringValue' => 'Stable']],
                        ]],
                    ]]],
                ],
            ],
        ];

        $http = new MockHttpClient([new MockResponse(json_encode($apiPayload) ?: '')]);
        $service = $this->makeService($http, 'api-key');
        $entries = $service->fetchSheet();

        self::assertCount(1, $entries);
        self::assertSame('Hollow Knight', $entries[0]->name);
    }

    private function makeService(MockHttpClient $http, string $googleApiKey): CatalogSyncService
    {
        return new CatalogSyncService($http, new ArrayAdapter(), new NullLogger(), 'fake-spreadsheet-id', $googleApiKey);
    }
}
