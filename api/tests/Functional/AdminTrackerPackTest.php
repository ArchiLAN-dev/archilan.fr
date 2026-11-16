<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Shared\Infrastructure\Double\NullMinioStorage;
use App\Tests\Fixtures\Trackers\TrackerPackZip;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** Story 54.1: importing a PopTracker pack onto a game from a GitHub link. */
final class AdminTrackerPackTest extends FunctionalTestCase
{
    private const string RELEASE_URL = 'https://github.com/G4M3RL1F3/Majoras-Mask-AP-PopTracker-Pack/releases/tag/v0.9.5.3';
    private const string ARCHIVE_URL = 'https://github.com/G4M3RL1F3/Majoras-Mask-AP-PopTracker-Pack/archive/refs/tags/v0.9.5.3.zip';

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        NullMinioStorage::reset();
    }

    #[\Override]
    protected function tearDown(): void
    {
        NullMinioStorage::reset();
        parent::tearDown();
    }

    public function testAdminImportsMajorasMaskFromItsReleasePage(): void
    {
        $this->loginAsAdmin();
        $game = $this->createGame("Majora's Mask Recompiled", 'mm-recomp');
        $archive = TrackerPackZip::sourceArchive();
        $this->mockGithub($archive);

        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/games/%s/tracker-packs', $game->getId()), ['url' => self::RELEASE_URL]);

        self::assertResponseStatusCodeSame(201);
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);
        self::assertSame("Majora's Mask: Recompiled", $data['name']);
        self::assertSame('0.9.5.3', $data['packageVersion']);
        self::assertTrue($data['hasApVariant']);

        $this->client->request('GET', sprintf('/api/v1/admin/games/%s/tracker-packs', $game->getId()));
        self::assertResponseStatusCodeSame(200);
        $packs = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($packs);
        self::assertCount(1, $packs);
        $pack = $packs[0];
        self::assertIsArray($pack);
        self::assertSame('MM_AP', $pack['packageUid']);
        self::assertSame('MIT', $pack['licenseSpdx']);
        self::assertSame('https://github.com/G4M3RL1F3/Majoras-Mask-AP-PopTracker-Pack', $pack['sourceUrl']);
        self::assertSame('v0.9.5.3', $pack['gitTag']);
        self::assertSame(strlen($archive), $pack['sizeBytes']);
        self::assertTrue($pack['hasApVariant']);
        self::assertSame([
            ['uid' => 'standard', 'displayName' => 'Maps and items', 'flags' => ['ap']],
            ['uid' => 'itemsonly', 'displayName' => 'Items only', 'flags' => ['ap']],
        ], $pack['variants']);

        $row = $this->entityManager->getConnection()->fetchAssociative('SELECT root_path, sha256, storage_key, download_url FROM tracker_pack_version');
        self::assertIsArray($row);
        self::assertSame('Majoras-Mask-AP-PopTracker-Pack-0.9.5.3', $row['root_path']);
        self::assertSame(hash('sha256', $archive), $row['sha256']);
        self::assertSame(self::ARCHIVE_URL, $row['download_url']);
        self::assertIsString($row['storage_key']);
        self::assertStringStartsWith('tracker-packs/', $row['storage_key']);
        // The zip is stored as downloaded.
        self::assertContains($archive, $this->storedObjects());
    }

    public function testSecondImportOfTheSamePackIsAConflict(): void
    {
        // The mocked GitHub lives in the container: keep the same one across both requests.
        $this->client->disableReboot();
        $this->loginAsAdmin();
        $game = $this->createGame("Majora's Mask Recompiled", 'mm-recomp');
        $this->mockGithub(TrackerPackZip::sourceArchive());
        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/games/%s/tracker-packs', $game->getId()), ['url' => self::RELEASE_URL]);
        self::assertResponseStatusCodeSame(201);

        $this->mockGithub(TrackerPackZip::sourceArchive());
        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/games/%s/tracker-packs', $game->getId()), ['url' => self::RELEASE_URL]);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('tracker_pack_already_imported', $this->errorCode());
        self::assertSame(1, $this->countRows('tracker_pack'));
    }

    public function testLinkOutsideGithubIsRefusedWithoutAnyWrite(): void
    {
        $this->loginAsAdmin();
        $game = $this->createGame('Some Game', 'some-game');

        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/games/%s/tracker-packs', $game->getId()), ['url' => 'https://example.com/pack.zip']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('tracker_pack_url_invalid', $this->errorCode());
        self::assertSame(0, $this->countRows('tracker_pack'));
        self::assertSame([], $this->storedObjects());
    }

    public function testZipWithoutManifestIsRefusedWithoutAnyWrite(): void
    {
        $this->loginAsAdmin();
        $game = $this->createGame('Some Game', 'some-game');
        $this->mockGithub(TrackerPackZip::build(['readme.md' => 'not a pack']));

        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/games/%s/tracker-packs', $game->getId()), ['url' => self::RELEASE_URL]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('tracker_pack_manifest_missing', $this->errorCode());
        self::assertSame(0, $this->countRows('tracker_pack'));
        self::assertSame([], $this->storedObjects());
    }

    public function testUnknownGameIsNotFound(): void
    {
        $this->loginAsAdmin();

        $this->client->jsonRequest('POST', '/api/v1/admin/games/unknown/tracker-packs', ['url' => self::RELEASE_URL]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testGithubRateLimitIsUnavailable(): void
    {
        $this->loginAsAdmin();
        $game = $this->createGame('Some Game', 'some-game');
        $this->httpClient()->setResponseFactory([
            new MockResponse('{}', ['http_code' => 403, 'response_headers' => ['x-ratelimit-remaining' => '0']]),
        ]);

        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/games/%s/tracker-packs', $game->getId()), ['url' => self::RELEASE_URL]);

        self::assertResponseStatusCodeSame(503);
        self::assertSame('github_rate_limited', $this->errorCode());
    }

    public function testMemberCannotListOrImport(): void
    {
        $this->loginAs($this->createUser('member@example.org'));
        $game = $this->createGame('Some Game', 'some-game');

        $this->client->request('GET', sprintf('/api/v1/admin/games/%s/tracker-packs', $game->getId()));
        self::assertResponseStatusCodeSame(403);

        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/games/%s/tracker-packs', $game->getId()), ['url' => self::RELEASE_URL]);
        self::assertResponseStatusCodeSame(403);
    }

    /** GitHub as it answers for the MM release: no asset, so the tag's source archive, then the repository. */
    private function mockGithub(string $archive): void
    {
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

    /** @return list<string> */
    private function storedObjects(): array
    {
        $storage = self::getContainer()->get(NullMinioStorage::class);
        self::assertInstanceOf(NullMinioStorage::class, $storage);

        return array_values($storage->getStore());
    }

    private function errorCode(): ?string
    {
        $error = $this->decodedJsonResponse()['error'] ?? null;
        $code = is_array($error) ? ($error['code'] ?? null) : null;

        return is_string($code) ? $code : null;
    }

    private function countRows(string $table): int
    {
        $count = $this->entityManager->getConnection()->fetchOne(sprintf('SELECT COUNT(*) FROM %s', $table));

        return is_numeric($count) ? (int) $count : -1;
    }

    private function loginAsAdmin(): void
    {
        $this->loginAs($this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']));
    }
}
