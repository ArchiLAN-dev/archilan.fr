<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Shared\Infrastructure\Double\NullMinioStorage;
use App\Trackers\Domain\Entity\TrackerPack;
use App\Trackers\Domain\Entity\TrackerPackVersion;
use App\Trackers\Domain\ValueObject\TrackerPackManifest;

/** Story 54.2: which packs of a game players are offered, and the zips they download. */
final class TrackerPackSharingTest extends FunctionalTestCase
{
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

    public function testImportedPackStaysHiddenUntilEnabled(): void
    {
        $game = $this->createGame('Majora', 'majora');
        [$pack, $version] = $this->storePack($game->getId(), 'MM_AP', 'MIT', 'ZIP-MM');

        $this->client->request('GET', sprintf('/api/v1/games/%s/tracker-packs', $game->getId()));
        self::assertResponseStatusCodeSame(200);
        self::assertSame([], $this->data());

        $this->client->request('GET', sprintf('/api/v1/tracker-pack-versions/%s/archive', $version->getId()));
        self::assertResponseStatusCodeSame(404);

        $this->loginAsAdmin();
        $this->patch($pack->getId(), ['enabled' => true]);
        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', sprintf('/api/v1/games/%s/tracker-packs', $game->getId()));
        self::assertResponseStatusCodeSame(200);
        $packs = $this->data();
        self::assertCount(1, $packs);
        $listed = $packs[0];
        self::assertIsArray($listed);
        self::assertSame($pack->getId(), $listed['id']);
        self::assertTrue($listed['isDefault']);
        self::assertTrue($listed['hasApVariant']);
        self::assertSame('MIT', $listed['licenseSpdx']);
        self::assertIsArray($listed['version']);
        self::assertSame($version->getId(), $listed['version']['id']);
        self::assertSame('Pack-1.0', $listed['version']['rootPath']);
        self::assertSame(hash('sha256', 'ZIP-MM'), $listed['version']['sha256']);
    }

    public function testEnabledPackArchiveIsServedAsStored(): void
    {
        $game = $this->createGame('Majora', 'majora');
        [$pack, $version] = $this->storePack($game->getId(), 'MM_AP', 'MIT', 'ZIP-MM');
        $this->loginAsAdmin();
        $this->patch($pack->getId(), ['enabled' => true]);
        // Back to a visitor: no session left.
        $this->client->getCookieJar()->clear();

        $this->client->request('GET', sprintf('/api/v1/tracker-pack-versions/%s/archive', $version->getId()));

        self::assertResponseStatusCodeSame(200);
        $response = $this->client->getResponse();
        self::assertSame('ZIP-MM', $response->getContent());
        self::assertSame('application/zip', $response->headers->get('Content-Type'));
        self::assertSame('"'.hash('sha256', 'ZIP-MM').'"', $response->headers->get('ETag'));
        self::assertSame('attachment; filename="MM_AP-1.0.zip"', $response->headers->get('Content-Disposition'));
    }

    public function testAdminReachesTheArchiveOfAPackNotYetOffered(): void
    {
        $game = $this->createGame('Majora', 'majora');
        [, $version] = $this->storePack($game->getId(), 'MM_AP', 'MIT', 'ZIP-MM');
        $this->loginAsAdmin();

        $this->client->request('GET', sprintf('/api/v1/tracker-pack-versions/%s/archive', $version->getId()));

        self::assertResponseStatusCodeSame(200);
        self::assertSame('ZIP-MM', $this->client->getResponse()->getContent());
    }

    public function testDefaultPackIsListedFirstAndMovesWhenDisabled(): void
    {
        $game = $this->createGame('Majora', 'majora');
        [$n64] = $this->storePack($game->getId(), 'MM_N64', 'MIT', 'ZIP-N64', '2026-11-01');
        [$recomp] = $this->storePack($game->getId(), 'MM_AP', 'MIT', 'ZIP-MM', '2026-11-02');
        $this->loginAsAdmin();
        $this->patch($n64->getId(), ['enabled' => true]);
        $this->patch($recomp->getId(), ['enabled' => true]);

        $this->patch($recomp->getId(), ['isDefault' => true]);
        self::assertResponseStatusCodeSame(204);
        self::assertSame([$recomp->getId(), $n64->getId()], $this->listedIds($game->getId()));

        $this->patch($recomp->getId(), ['enabled' => false]);
        self::assertResponseStatusCodeSame(204);
        $this->client->request('GET', sprintf('/api/v1/games/%s/tracker-packs', $game->getId()));
        $packs = $this->data();
        self::assertCount(1, $packs);
        self::assertIsArray($packs[0]);
        self::assertSame($n64->getId(), $packs[0]['id']);
        self::assertTrue($packs[0]['isDefault']);
    }

    public function testPackWithoutLicenceNeedsTheAcknowledgement(): void
    {
        $game = $this->createGame('Majora', 'majora');
        [$pack] = $this->storePack($game->getId(), 'MM_AP', null, 'ZIP-MM');
        $this->loginAsAdmin();

        $this->patch($pack->getId(), ['enabled' => true]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('tracker_pack_license_unacknowledged', $this->errorCode());

        $this->patch($pack->getId(), ['licenseAcknowledged' => true, 'enabled' => true]);
        self::assertResponseStatusCodeSame(204);
        self::assertSame([$pack->getId()], $this->listedIds($game->getId()));
    }

    public function testDefaultVariantMustBeOneOfThePack(): void
    {
        $game = $this->createGame('Majora', 'majora');
        [$pack] = $this->storePack($game->getId(), 'MM_AP', 'MIT', 'ZIP-MM');
        $this->loginAsAdmin();

        $this->patch($pack->getId(), ['defaultVariantUid' => 'mapsonly']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('tracker_pack_variant_unknown', $this->errorCode());

        $this->patch($pack->getId(), ['defaultVariantUid' => 'itemsonly', 'enabled' => true]);
        self::assertResponseStatusCodeSame(204);
        $this->client->request('GET', sprintf('/api/v1/games/%s/tracker-packs', $game->getId()));
        $packs = $this->data();
        self::assertIsArray($packs[0]);
        self::assertSame('itemsonly', $packs[0]['defaultVariantUid']);

        $this->client->request('GET', sprintf('/api/v1/admin/games/%s/tracker-packs', $game->getId()));
        $admin = $this->data();
        self::assertIsArray($admin[0]);
        self::assertTrue($admin[0]['enabled']);
        self::assertTrue($admin[0]['isDefault']);
        self::assertSame('itemsonly', $admin[0]['defaultVariantUid']);
    }

    public function testMalformedChangesAreRefused(): void
    {
        $game = $this->createGame('Majora', 'majora');
        [$pack] = $this->storePack($game->getId(), 'MM_AP', 'MIT', 'ZIP-MM');
        $this->loginAsAdmin();

        $this->patch($pack->getId(), ['isDefault' => false]);
        self::assertResponseStatusCodeSame(400);
        $this->patch($pack->getId(), ['enabled' => 'yes']);
        self::assertResponseStatusCodeSame(400);
        $this->patch('unknown', ['enabled' => true]);
        self::assertResponseStatusCodeSame(404);
        $this->patch($pack->getId(), ['isDefault' => true]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('tracker_pack_not_enabled', $this->errorCode());
    }

    public function testMemberCannotChangeAPack(): void
    {
        $game = $this->createGame('Majora', 'majora');
        [$pack] = $this->storePack($game->getId(), 'MM_AP', 'MIT', 'ZIP-MM');
        $this->loginAs($this->createUser('member@example.org'));

        $this->patch($pack->getId(), ['enabled' => true]);

        self::assertResponseStatusCodeSame(403);
    }

    /** @return array{0: TrackerPack, 1: TrackerPackVersion} */
    private function storePack(string $gameId, string $uid, ?string $license, string $zip, string $day = '2026-11-01'): array
    {
        $manifest = TrackerPackManifest::fromArray([
            'package_uid' => $uid,
            'name' => 'Pack '.$uid,
            'package_version' => '1.0',
            'variants' => [
                'standard' => ['display_name' => 'Maps and items', 'flags' => ['ap']],
                'itemsonly' => ['display_name' => 'Items only', 'flags' => ['ap']],
            ],
        ]);
        $now = new \DateTimeImmutable($day.'T10:00:00+00:00');
        $packId = substr(hash('sha256', $uid), 0, 32);
        $versionId = substr(hash('sha256', $uid.'-v'), 0, 32);
        $key = TrackerPackVersion::storageKeyFor($packId, $versionId);
        $storage = self::getContainer()->get(NullMinioStorage::class);
        self::assertInstanceOf(NullMinioStorage::class, $storage);
        $storage->upload('apworlds', $key, $zip);

        $version = TrackerPackVersion::record($versionId, $packId, $manifest, 'v1.0', 'https://github.com/o/r/archive/refs/tags/v1.0.zip', hash('sha256', $zip), $key, 'Pack-1.0', strlen($zip), $now);
        $pack = TrackerPack::import($packId, $gameId, 'https://github.com/o/r', $manifest, $license, $versionId, 'admin', $now);
        $this->entityManager->persist($version);
        $this->entityManager->persist($pack);
        $this->entityManager->flush();

        return [$pack, $version];
    }

    /** @param array<string, mixed> $changes */
    private function patch(string $packId, array $changes): void
    {
        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/tracker-packs/%s', $packId), $changes);
    }

    /** @return list<string> */
    private function listedIds(string $gameId): array
    {
        $this->client->request('GET', sprintf('/api/v1/games/%s/tracker-packs', $gameId));
        self::assertResponseStatusCodeSame(200);

        return array_values(array_map(static fn (mixed $p): string => is_array($p) && is_string($p['id'] ?? null) ? $p['id'] : '', $this->data()));
    }

    /** @return array<mixed> */
    private function data(): array
    {
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);

        return $data;
    }

    private function errorCode(): ?string
    {
        $error = $this->decodedJsonResponse()['error'] ?? null;
        $code = is_array($error) ? ($error['code'] ?? null) : null;

        return is_string($code) ? $code : null;
    }

    private function loginAsAdmin(): void
    {
        $this->loginAs($this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']));
    }
}
