<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\GameSelection\Application\Port\GameCatalogLinksProviderInterface;
use App\Shared\Infrastructure\Double\NullMinioStorage;
use App\Tests\Fixtures\Trackers\TrackerPackZip;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** Story 54.12: the PopTracker packs the community catalog names, proposed to the admin. */
final class AdminCatalogTrackerPackTest extends FunctionalTestCase
{
    private const string ARCHIVE_URL = 'https://github.com/G4M3RL1F3/Majoras-Mask-AP-PopTracker-Pack/archive/refs/tags/v0.9.5.3.zip';

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        NullMinioStorage::reset();
        $this->client->disableReboot();
    }

    #[\Override]
    protected function tearDown(): void
    {
        NullMinioStorage::reset();
        parent::tearDown();
    }

    public function testGameGetsTheCatalogPacksItDoesNotHave(): void
    {
        $this->loginAsAdmin();
        $game = $this->createGame("The Legend of Zelda: Majora's Mask", 'mm');
        $this->catalogLinks([
            "The Legend of Zelda: Majora's Mask" => [
                ['label' => 'Github Releases', 'url' => 'https://github.com/someone/mm-apworld/releases'],
                ['label' => 'Poptracker', 'url' => 'https://www.google.com/url?q=https://github.com/seto10987/Majoras-Mask-AP-PopTracker-Pack/releases/latest&sa=D'],
                ['label' => 'Poptracker (Discord)', 'url' => 'https://discord.com/channels/1/2/3'],
            ],
        ]);

        $this->client->request('GET', sprintf('/api/v1/admin/games/%s/tracker-pack-suggestions', $game->getId()));

        self::assertResponseStatusCodeSame(200);
        self::assertSame([
            [
                'label' => 'Poptracker',
                'url' => 'https://github.com/seto10987/Majoras-Mask-AP-PopTracker-Pack/releases/latest',
                'importUrl' => 'https://github.com/seto10987/Majoras-Mask-AP-PopTracker-Pack',
                'repository' => 'seto10987/Majoras-Mask-AP-PopTracker-Pack',
            ],
            [
                'label' => 'Poptracker (Discord)',
                'url' => 'https://discord.com/channels/1/2/3',
                'importUrl' => null,
                'repository' => null,
            ],
        ], $this->decodedJsonResponse()['data'] ?? null);
    }

    public function testImportedPackIsNoLongerProposed(): void
    {
        $this->loginAsAdmin();
        $mm = $this->createGame("Majora's Mask Recompiled", 'mm-recomp');
        $other = $this->createGame('Another Game', 'another-game');
        $this->catalogLinks([
            "Majora's Mask Recompiled" => [['label' => 'Poptracker', 'url' => 'https://github.com/g4m3rl1f3/majoras-mask-ap-poptracker-pack/releases/latest']],
            'Another Game' => [['label' => 'Poptracker', 'url' => 'https://github.com/someone/another-pack']],
        ]);
        $this->mockGithub();
        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/games/%s/tracker-packs', $mm->getId()), [
            'url' => 'https://github.com/G4M3RL1F3/Majoras-Mask-AP-PopTracker-Pack/releases/tag/v0.9.5.3',
        ]);
        self::assertResponseStatusCodeSame(201);

        $this->client->request('GET', sprintf('/api/v1/admin/games/%s/tracker-pack-suggestions', $mm->getId()));
        self::assertResponseStatusCodeSame(200);
        self::assertSame([], $this->decodedJsonResponse()['data'] ?? null);

        $this->client->request('GET', '/api/v1/admin/tracker-pack-suggestions');
        self::assertResponseStatusCodeSame(200);
        self::assertSame([[
            'gameId' => $other->getId(),
            'gameName' => 'Another Game',
            'gameSlug' => 'another-game',
            'suggestions' => [[
                'label' => 'Poptracker',
                'url' => 'https://github.com/someone/another-pack',
                'importUrl' => 'https://github.com/someone/another-pack',
                'repository' => 'someone/another-pack',
            ]],
        ]], $this->decodedJsonResponse()['data'] ?? null);
    }

    public function testUnreadableCatalogProposesNothing(): void
    {
        $this->loginAsAdmin();
        $game = $this->createGame('Some Game', 'some-game');
        // The real catalog, whose sheet answers 500: no links, no error.
        $this->httpClient()->setResponseFactory(static fn (): MockResponse => new MockResponse('down', ['http_code' => 500]));

        $this->client->request('GET', sprintf('/api/v1/admin/games/%s/tracker-pack-suggestions', $game->getId()));
        self::assertResponseStatusCodeSame(200);
        self::assertSame([], $this->decodedJsonResponse()['data'] ?? null);

        $this->client->request('GET', '/api/v1/admin/tracker-pack-suggestions');
        self::assertResponseStatusCodeSame(200);
        self::assertSame([], $this->decodedJsonResponse()['data'] ?? null);
    }

    public function testUnknownGameIsNotFound(): void
    {
        $this->loginAsAdmin();

        $this->client->request('GET', '/api/v1/admin/games/00000000-0000-0000-0000-000000000000/tracker-pack-suggestions');

        self::assertResponseStatusCodeSame(404);
    }

    public function testMemberCannotSeeTheSuggestions(): void
    {
        $this->loginAs($this->createUser('member@example.org'));
        $game = $this->createGame('Some Game', 'some-game');

        $this->client->request('GET', sprintf('/api/v1/admin/games/%s/tracker-pack-suggestions', $game->getId()));
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', '/api/v1/admin/tracker-pack-suggestions');
        self::assertResponseStatusCodeSame(403);
    }

    /** @param array<string, list<array{label: string, url: ?string}>> $byGameName */
    private function catalogLinks(array $byGameName): void
    {
        self::getContainer()->set(GameCatalogLinksProviderInterface::class, new class($byGameName) implements GameCatalogLinksProviderInterface {
            /** @param array<string, list<array{label: string, url: ?string}>> $byGameName */
            public function __construct(private array $byGameName)
            {
            }

            public function linksFor(?string $catalogSheetName, ?string $archipelagoGameName, string $name): array
            {
                return $this->byGameName[$name] ?? [];
            }
        });
    }

    /** GitHub as it answers for the MM release: no asset, so the tag's source archive, then the repository. */
    private function mockGithub(): void
    {
        $archive = TrackerPackZip::sourceArchive();
        $this->httpClient()->setResponseFactory(static fn (string $method, string $url): MockResponse => match ($url) {
            'https://api.github.com/repos/G4M3RL1F3/Majoras-Mask-AP-PopTracker-Pack/releases/tags/v0.9.5.3' => new MockResponse(
                json_encode(['tag_name' => 'v0.9.5.3', 'assets' => []], \JSON_THROW_ON_ERROR),
            ),
            self::ARCHIVE_URL => new MockResponse($archive),
            'https://api.github.com/repos/G4M3RL1F3/Majoras-Mask-AP-PopTracker-Pack' => new MockResponse(
                json_encode(['license' => ['spdx_id' => 'MIT']], \JSON_THROW_ON_ERROR),
            ),
            default => new MockResponse('unexpected', ['http_code' => 500]),
        });
    }

    private function httpClient(): MockHttpClient
    {
        $client = self::getContainer()->get(MockHttpClient::class);
        self::assertInstanceOf(MockHttpClient::class, $client);

        return $client;
    }

    private function loginAsAdmin(): void
    {
        $this->loginAs($this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']));
    }
}
