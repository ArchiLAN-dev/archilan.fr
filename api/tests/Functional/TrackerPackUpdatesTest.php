<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Shared\Infrastructure\Double\NullMinioStorage;
use App\Tests\Fixtures\Trackers\TrackerPackZip;
use App\Trackers\Application\Handler\CheckTrackerPackUpdatesHandler;
use App\Trackers\Application\Message\CheckTrackerPackUpdatesMessage;
use App\Trackers\Domain\Entity\TrackerPack;
use App\Trackers\Domain\Entity\TrackerPackVersion;
use App\Trackers\Domain\ValueObject\TrackerPackManifest;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** Story 54.3: finding, installing and undoing the new versions of a pack. */
final class TrackerPackUpdatesTest extends FunctionalTestCase
{
    private const string VERSIONS_URL = 'https://raw.githubusercontent.com/o/r/refs/heads/cube/versions.json';
    private const string LATEST = 'https://api.github.com/repos/o/r/releases/latest';

    /** @var array<string, MockResponse|\Closure(): MockResponse> */
    private array $github = [];

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        NullMinioStorage::reset();
        // The mocked GitHub lives in the container: keep the same one across requests.
        $this->client->disableReboot();
        $this->httpClient()->setResponseFactory(fn (string $method, string $url): MockResponse => match (true) {
            !isset($this->github[$url]) => new MockResponse('unexpected '.$url, ['http_code' => 500]),
            $this->github[$url] instanceof \Closure => ($this->github[$url])(),
            default => $this->github[$url],
        });
    }

    #[\Override]
    protected function tearDown(): void
    {
        NullMinioStorage::reset();
        parent::tearDown();
    }

    public function testLaggingVersionsFileDoesNotHideTheNewerRelease(): void
    {
        $pack = $this->storePack('0.9.5.3');
        $this->github[self::VERSIONS_URL] = self::versionsFile('0.9.5.2');
        $this->github[self::LATEST] = self::release('v0.9.5.4', "- Fixed the Great Bay logic\n- New maps");
        $this->loginAsAdmin();

        $this->client->request('POST', sprintf('/api/v1/admin/tracker-packs/%s/check-update', $pack->getId()));

        self::assertResponseStatusCodeSame(204);
        $view = $this->adminView();
        self::assertIsArray($view['availableUpdate']);
        self::assertSame('0.9.5.4', $view['availableUpdate']['packageVersion']);
        self::assertSame(['- Fixed the Great Bay logic', '- New maps'], $view['availableUpdate']['changelog']);
        self::assertIsString($view['lastCheckedAt']);
        self::assertNull($view['lastCheckError']);
    }

    /** Story 54.13: a versions file cannot send the update to another repository. */
    public function testDownloadOutsideThePackRepositoryIsIgnored(): void
    {
        $pack = $this->storePack('0.9.5.3', enabled: true);
        $this->github[self::VERSIONS_URL] = self::versionsFile('9.9.9', null, 'https://github.com/someone/else/releases/download/v9.9.9/pack.zip');
        $this->github[self::LATEST] = self::release('v0.9.5.3', '');
        $this->loginAsAdmin();

        $this->client->request('POST', sprintf('/api/v1/admin/tracker-packs/%s/check-update', $pack->getId()));

        self::assertResponseStatusCodeSame(204);
        self::assertNull($this->adminView()['availableUpdate']);
    }

    public function testNothingNewLeavesNoUpdate(): void
    {
        $pack = $this->storePack('0.9.5.3');
        $this->github[self::VERSIONS_URL] = self::versionsFile('0.9.5.2');
        $this->github[self::LATEST] = self::release('v0.9.5.3', '');
        $this->loginAsAdmin();

        $this->client->request('POST', sprintf('/api/v1/admin/tracker-packs/%s/check-update', $pack->getId()));

        self::assertResponseStatusCodeSame(204);
        self::assertNull($this->adminView()['availableUpdate']);
    }

    public function testUpdateInstallsTheNewVersionAndServesIt(): void
    {
        $pack = $this->storePack('0.9.5.3', defaultVariant: 'itemsonly');
        $zip = TrackerPackZip::sourceArchive('Pack-0.9.5.4', self::manifest('0.9.5.4', withItemsOnly: false));
        $this->github[self::VERSIONS_URL] = self::versionsFile('0.9.5.2');
        $this->github[self::LATEST] = self::release('v0.9.5.4', 'Notes');
        $this->github['https://github.com/o/r/archive/refs/tags/v0.9.5.4.zip'] = new MockResponse($zip);
        $this->loginAsAdmin();
        $this->client->request('POST', sprintf('/api/v1/admin/tracker-packs/%s/check-update', $pack->getId()));

        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/tracker-packs/%s/update', $pack->getId()), []);

        self::assertResponseStatusCodeSame(200);
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);
        self::assertSame('0.9.5.4', $data['packageVersion']);
        // The default variant is gone from 0.9.5.4: players get the first one again.
        self::assertTrue($data['defaultVariantReset']);

        $view = $this->adminView();
        self::assertSame('0.9.5.4', $view['packageVersion']);
        self::assertNull($view['availableUpdate']);
        self::assertNull($view['defaultVariantUid']);
        self::assertIsArray($view['versions']);
        self::assertCount(2, $view['versions']);

        self::assertIsString($view['versionId']);
        $this->client->request('GET', sprintf('/api/v1/tracker-pack-versions/%s/archive', $view['versionId']));
        self::assertResponseStatusCodeSame(200);
        self::assertSame($zip, $this->client->getResponse()->getContent());

        // Story 54.13: a browser that holds this zip gets a 304, without the zip.
        $etag = $this->client->getResponse()->headers->get('ETag');
        self::assertIsString($etag);
        $this->client->request('GET', sprintf('/api/v1/tracker-pack-versions/%s/archive', $view['versionId']), server: ['HTTP_IF_NONE_MATCH' => $etag]);
        self::assertResponseStatusCodeSame(304);
        self::assertSame('', $this->client->getResponse()->getContent());
    }

    public function testWrongChecksumBlocksTheUpdate(): void
    {
        $pack = $this->storePack('0.9.5.3');
        $this->github[self::VERSIONS_URL] = self::versionsFile('0.9.5.4', str_repeat('a', 64), 'https://github.com/o/r/releases/download/v0.9.5.4/pack.zip');
        $this->github[self::LATEST] = new MockResponse('{}', ['http_code' => 404]);
        $this->github['https://github.com/o/r/releases/download/v0.9.5.4/pack.zip'] = new MockResponse(TrackerPackZip::sourceArchive('P', self::manifest('0.9.5.4')));
        $this->loginAsAdmin();
        $this->client->request('POST', sprintf('/api/v1/admin/tracker-packs/%s/check-update', $pack->getId()));

        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/tracker-packs/%s/update', $pack->getId()), []);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('tracker_pack_checksum_mismatch', $this->errorCode());
        self::assertSame('0.9.5.3', $this->adminView()['packageVersion']);
    }

    public function testZipOfAnotherPackIsRefused(): void
    {
        $pack = $this->storePack('0.9.5.3');
        $this->github['https://api.github.com/repos/o/r/releases/tags/v2'] = self::release('v2', '');
        $this->github['https://github.com/o/r/archive/refs/tags/v2.zip'] = new MockResponse(TrackerPackZip::sourceArchive('P', str_replace('"MM_AP"', '"OOT_AP"', self::manifest('2.0'))));
        $this->github['https://api.github.com/repos/o/r'] = new MockResponse('{"license": {"spdx_id": "MIT"}}');
        $this->loginAsAdmin();

        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/tracker-packs/%s/update', $pack->getId()), ['url' => 'https://github.com/o/r/releases/tag/v2']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('tracker_pack_uid_mismatch', $this->errorCode());
        self::assertSame('0.9.5.3', $this->adminView()['packageVersion']);
    }

    public function testUpdateFromALinkThenBackToTheOldVersion(): void
    {
        $pack = $this->storePack('0.9.5.3');
        $first = $pack->getCurrentVersionId();
        $this->github['https://api.github.com/repos/o/r/releases/tags/v0.9.5.4'] = self::release('v0.9.5.4', '');
        $this->github['https://github.com/o/r/archive/refs/tags/v0.9.5.4.zip'] = new MockResponse(TrackerPackZip::sourceArchive('P', self::manifest('0.9.5.4')));
        $this->github['https://api.github.com/repos/o/r'] = new MockResponse('{"license": {"spdx_id": "MIT"}}');
        $this->loginAsAdmin();
        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/tracker-packs/%s/update', $pack->getId()), ['url' => 'https://github.com/o/r/releases/tag/v0.9.5.4']);
        self::assertResponseStatusCodeSame(200);
        self::assertSame('0.9.5.4', $this->adminView()['packageVersion']);

        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/tracker-packs/%s/rollback', $pack->getId()), ['versionId' => $first]);

        self::assertResponseStatusCodeSame(200);
        $view = $this->adminView();
        self::assertSame('0.9.5.3', $view['packageVersion']);
        self::assertSame($first, $view['versionId']);

        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/tracker-packs/%s/rollback', $pack->getId()), ['versionId' => 'unknown']);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('tracker_pack_version_not_found', $this->errorCode());
    }

    /** Story 54.13: players keep their version until an admin tried the new one and serves it. */
    public function testAnEnabledPackKeepsItsVersionUntilAnAdminServesTheNewOne(): void
    {
        $pack = $this->storePack('0.9.5.3', enabled: true);
        $first = $pack->getCurrentVersionId();
        $zip = TrackerPackZip::sourceArchive('Pack-0.9.5.4', self::manifest('0.9.5.4'));
        $this->github[self::VERSIONS_URL] = self::versionsFile('0.9.5.2');
        $this->github[self::LATEST] = self::release('v0.9.5.4', 'Notes');
        $this->github['https://github.com/o/r/archive/refs/tags/v0.9.5.4.zip'] = new MockResponse($zip);
        $this->loginAsAdmin();
        $this->client->request('POST', sprintf('/api/v1/admin/tracker-packs/%s/check-update', $pack->getId()));

        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/tracker-packs/%s/update', $pack->getId()), []);

        self::assertResponseStatusCodeSame(200);
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);
        self::assertFalse($data['served']);
        self::assertIsString($data['versionId']);
        $view = $this->adminView();
        self::assertSame($first, $view['versionId']);
        self::assertSame('0.9.5.3', $view['packageVersion']);
        // Installed: the offer is gone, the version waits among the kept ones.
        self::assertNull($view['availableUpdate']);
        self::assertIsArray($view['versions']);
        self::assertCount(2, $view['versions']);

        // Players still get the old zip; the admin's trial reads the new one.
        $adminCookies = $this->client->getCookieJar()->all();
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', sprintf('/api/v1/tracker-pack-versions/%s/archive', $data['versionId']));
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', sprintf('/api/v1/tracker-pack-versions/%s/archive', $first));
        self::assertResponseStatusCodeSame(200);

        foreach ($adminCookies as $cookie) {
            $this->client->getCookieJar()->set($cookie);
        }
        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/tracker-packs/%s/rollback', $pack->getId()), ['versionId' => $data['versionId']]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame('0.9.5.4', $this->adminView()['packageVersion']);
    }

    public function testInstallingAKeptZipAgainServesThatVersionWithoutACopy(): void
    {
        $pack = $this->storePack('0.9.5.3');
        $first = $pack->getCurrentVersionId();
        $this->github['https://api.github.com/repos/o/r'] = new MockResponse('{"license": {"spdx_id": "MIT"}}');
        $this->github['https://api.github.com/repos/o/r/releases/tags/v0.9.5.4'] = self::release('v0.9.5.4', '');
        $this->github['https://github.com/o/r/archive/refs/tags/v0.9.5.4.zip'] = new MockResponse(TrackerPackZip::sourceArchive('P', self::manifest('0.9.5.4')));
        $this->github['https://api.github.com/repos/o/r/releases/tags/v0.9.5.3'] = self::release('v0.9.5.3', '');
        // The zip of the first version, byte for byte.
        $this->github['https://github.com/o/r/archive/refs/tags/v0.9.5.3.zip'] = new MockResponse('ZIP-0.9.5.3');
        $this->loginAsAdmin();
        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/tracker-packs/%s/update', $pack->getId()), ['url' => 'https://github.com/o/r/releases/tag/v0.9.5.4']);
        self::assertResponseStatusCodeSame(200);

        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/tracker-packs/%s/update', $pack->getId()), ['url' => 'https://github.com/o/r/releases/tag/v0.9.5.3']);

        self::assertResponseStatusCodeSame(200);
        $view = $this->adminView();
        self::assertSame($first, $view['versionId']);
        self::assertIsArray($view['versions']);
        self::assertCount(2, $view['versions']);

        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/tracker-packs/%s/update', $pack->getId()), ['url' => 'https://github.com/o/r/releases/tag/v0.9.5.3']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('tracker_pack_version_already_served', $this->errorCode());
    }

    public function testOnlyTheThreeLatestVersionsAreKept(): void
    {
        $pack = $this->storePack('1.0');
        $firstKey = TrackerPackVersion::storageKeyFor($pack->getId(), $pack->getCurrentVersionId());
        $this->github['https://api.github.com/repos/o/r'] = new MockResponse('{"license": {"spdx_id": "MIT"}}');
        $this->loginAsAdmin();
        foreach (['1.1', '1.2', '1.3'] as $version) {
            $this->github[sprintf('https://api.github.com/repos/o/r/releases/tags/v%s', $version)] = self::release('v'.$version, '');
            $this->github[sprintf('https://github.com/o/r/archive/refs/tags/v%s.zip', $version)] = new MockResponse(TrackerPackZip::sourceArchive('P', self::manifest($version)));
            $this->client->jsonRequest('POST', sprintf('/api/v1/admin/tracker-packs/%s/update', $pack->getId()), ['url' => sprintf('https://github.com/o/r/releases/tag/v%s', $version)]);
            self::assertResponseStatusCodeSame(200);
        }

        $view = $this->adminView();
        self::assertIsArray($view['versions']);
        self::assertSame(['1.3', '1.2', '1.1'], array_column($view['versions'], 'packageVersion'));
        self::assertSame([true, false, false], array_column($view['versions'], 'current'));
        self::assertArrayNotHasKey('apworlds/'.$firstKey, $this->storage()->getStore());
        self::assertCount(3, $this->storage()->getStore());
    }

    public function testRateLimitStopsTheNightlyPassCleanly(): void
    {
        $first = $this->storePack('0.9.5.3', enabled: true);
        $second = $this->storePack('1.0', enabled: true, uid: 'OOT_AP');
        $calls = 0;
        $this->github[self::VERSIONS_URL] = self::versionsFile('0.9.5.2');
        $this->github[self::LATEST] = static function () use (&$calls): MockResponse {
            ++$calls;

            return new MockResponse('{}', ['http_code' => 403, 'response_headers' => ['x-ratelimit-remaining' => '0']]);
        };

        $handler = self::getContainer()->get(CheckTrackerPackUpdatesHandler::class);
        self::assertInstanceOf(CheckTrackerPackUpdatesHandler::class, $handler);
        $handler(new CheckTrackerPackUpdatesMessage());

        // The pass stopped at the first pack: the second was not asked for.
        self::assertSame(1, $calls);
        $this->entityManager->clear();
        $checked = $this->entityManager->find(TrackerPack::class, $first->getId());
        $untouched = $this->entityManager->find(TrackerPack::class, $second->getId());
        self::assertNotNull($checked);
        self::assertNotNull($untouched);
        $row = $this->entityManager->getConnection()->fetchAssociative('SELECT last_check_error, last_checked_at FROM tracker_pack WHERE id = ?', [$untouched->getId()]);
        self::assertIsArray($row);
        self::assertNull($row['last_checked_at']);
        self::assertNull($checked->getAvailableUpdate());
    }

    public function testMemberCannotCheckOrUpdate(): void
    {
        $pack = $this->storePack('0.9.5.3');
        $this->loginAs($this->createUser('member@example.org'));

        $this->client->request('POST', sprintf('/api/v1/admin/tracker-packs/%s/check-update', $pack->getId()));
        self::assertResponseStatusCodeSame(403);
        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/tracker-packs/%s/update', $pack->getId()), []);
        self::assertResponseStatusCodeSame(403);
    }

    private function storePack(string $version, string $defaultVariant = '', bool $enabled = false, string $uid = 'MM_AP'): TrackerPack
    {
        $game = $this->createGame('Majora '.$uid, 'majora-'.strtolower($uid));
        $manifest = TrackerPackManifest::fromArray([
            'package_uid' => $uid,
            'name' => 'Pack '.$uid,
            'package_version' => $version,
            'variants' => [
                'standard' => ['display_name' => 'Maps and items', 'flags' => ['ap']],
                'itemsonly' => ['display_name' => 'Items only', 'flags' => ['ap']],
            ],
            'versions_url' => self::VERSIONS_URL,
        ]);
        $now = new \DateTimeImmutable('2026-11-01T10:00:00+00:00');
        $packId = substr(hash('sha256', $uid), 0, 32);
        $versionId = substr(hash('sha256', $uid.$version), 0, 32);
        $key = TrackerPackVersion::storageKeyFor($packId, $versionId);
        $this->storage()->upload('apworlds', $key, 'ZIP-'.$version);

        $packVersion = TrackerPackVersion::record($versionId, $packId, $manifest, 'v'.$version, 'https://github.com/o/r/archive/refs/tags/v'.$version.'.zip', hash('sha256', 'ZIP-'.$version), $key, 'Pack-'.$version, 10, $now);
        $pack = TrackerPack::import($packId, $game->getId(), 'https://github.com/o/r', $manifest, 'MIT', $versionId, 'admin', $now);
        if ('' !== $defaultVariant) {
            $pack->chooseDefaultVariant($defaultVariant, ['standard', 'itemsonly']);
        }
        if ($enabled) {
            $pack->markEnabled(true);
        }
        $this->entityManager->persist($packVersion);
        $this->entityManager->persist($pack);
        $this->entityManager->flush();

        return $pack;
    }

    private static function manifest(string $version, bool $withItemsOnly = true): string
    {
        $variants = $withItemsOnly
            ? '{"standard": {"display_name": "Maps and items", "flags": ["ap"]}, "itemsonly": {"display_name": "Items only", "flags": ["ap"]}}'
            : '{"standard": {"display_name": "Maps and items", "flags": ["ap"]}}';

        return sprintf('{"name": "Pack MM_AP", "package_uid": "MM_AP", "package_version": "%s", "variants": %s}', $version, $variants);
    }

    private static function versionsFile(string $version, ?string $sha = null, ?string $url = null): MockResponse
    {
        return new MockResponse(json_encode(['versions' => [[
            'package_version' => $version,
            'download_url' => $url ?? 'https://github.com/o/r/archive/refs/tags/v'.$version.'.zip',
            'sha256' => $sha,
            'changelog' => ['From the versions file'],
        ]]], \JSON_THROW_ON_ERROR));
    }

    private static function release(string $tag, string $body): MockResponse
    {
        return new MockResponse(json_encode(['tag_name' => $tag, 'assets' => [], 'body' => $body], \JSON_THROW_ON_ERROR));
    }

    /** @return array<mixed> the only pack of the admin list of its game */
    private function adminView(): array
    {
        $gameId = $this->entityManager->getConnection()->fetchOne("SELECT game_id FROM tracker_pack WHERE package_uid = 'MM_AP'");
        self::assertIsString($gameId);
        $this->client->request('GET', sprintf('/api/v1/admin/games/%s/tracker-packs', $gameId));
        self::assertResponseStatusCodeSame(200);
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);
        self::assertIsArray($data[0]);

        return $data[0];
    }

    private function errorCode(): ?string
    {
        $error = $this->decodedJsonResponse()['error'] ?? null;
        $code = is_array($error) ? ($error['code'] ?? null) : null;

        return is_string($code) ? $code : null;
    }

    private function httpClient(): MockHttpClient
    {
        $client = self::getContainer()->get(MockHttpClient::class);
        self::assertInstanceOf(MockHttpClient::class, $client);

        return $client;
    }

    private function storage(): NullMinioStorage
    {
        $storage = self::getContainer()->get(NullMinioStorage::class);
        self::assertInstanceOf(NullMinioStorage::class, $storage);

        return $storage;
    }

    private function loginAsAdmin(): void
    {
        $this->loginAs($this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']));
    }
}
