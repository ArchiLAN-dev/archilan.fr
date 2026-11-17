<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Trackers\Domain\Entity\TrackerPack;
use App\Trackers\Domain\Entity\TrackerPackVersion;
use App\Trackers\Domain\ValueObject\TrackerPackManifest;

/** Story 54.8: what an admin's try of a pack variant found, kept on the version and shown on the game's admin page. */
final class TrackerPackCompatibilityTest extends FunctionalTestCase
{
    public function testAdminTryIsKeptPerVariantAndReplacedByTheNextOne(): void
    {
        $game = $this->createGame('Majora', 'majora');
        $version = $this->storePack($game->getId());
        $this->loginAsAdmin();

        $this->report($version->getId(), [
            'variantUid' => 'standard',
            'status' => 'warnings',
            'errors' => [['kind' => 'error', 'message' => 'scripts/init.lua:14: fichier introuvable', 'file' => 'scripts/init.lua', 'line' => 14]],
            'unsupportedApis' => ['ScriptHost:AddOnFrameHandler', 'ScriptHost:AddOnFrameHandler', 'widget:canvas'],
            'engineVersion' => 2,
        ]);
        self::assertResponseStatusCodeSame(204);
        $this->report($version->getId(), ['variantUid' => 'itemsonly', 'status' => 'failed', 'errors' => [['kind' => 'lua_error', 'message' => str_repeat('x', 900)]], 'unsupportedApis' => [], 'engineVersion' => 2]);
        self::assertResponseStatusCodeSame(204);

        $tries = $this->compatibility($game->getId());
        self::assertCount(2, $tries);
        self::assertSame('itemsonly', $tries[0]['variantUid']);
        self::assertSame('failed', $tries[0]['status']);
        $errors = $tries[0]['errors'];
        self::assertIsArray($errors);
        self::assertIsArray($errors[0]);
        self::assertSame(500, mb_strlen(is_string($errors[0]['message']) ? $errors[0]['message'] : ''));
        self::assertNull($errors[0]['file']);
        self::assertSame('standard', $tries[1]['variantUid']);
        self::assertSame(['ScriptHost:AddOnFrameHandler', 'widget:canvas'], $tries[1]['unsupportedApis']);
        self::assertSame(2, $tries[1]['engineVersion']);

        // A new try of the same variant replaces the previous one.
        $this->report($version->getId(), ['variantUid' => 'itemsonly', 'status' => 'ok', 'errors' => [], 'unsupportedApis' => [], 'engineVersion' => 3]);
        self::assertResponseStatusCodeSame(204);
        $tries = $this->compatibility($game->getId());
        self::assertCount(2, $tries);
        self::assertSame('ok', $tries[0]['status']);
        self::assertSame([], $tries[0]['errors']);
        self::assertSame(3, $tries[0]['engineVersion']);
    }

    public function testReportOutOfBoundsOrMalformedIsRefused(): void
    {
        $game = $this->createGame('Majora', 'majora');
        $version = $this->storePack($game->getId());
        $this->loginAsAdmin();
        $valid = ['variantUid' => 'standard', 'status' => 'ok', 'errors' => [], 'unsupportedApis' => [], 'engineVersion' => 1];

        $this->report($version->getId(), [...$valid, 'errors' => array_fill(0, 51, ['kind' => 'error', 'message' => 'x'])]);
        self::assertResponseStatusCodeSame(422);
        $this->report($version->getId(), [...$valid, 'unsupportedApis' => array_map(static fn (int $i): string => 'Api'.$i, range(1, 101))]);
        self::assertResponseStatusCodeSame(422);
        $this->report($version->getId(), [...$valid, 'status' => 'great']);
        self::assertResponseStatusCodeSame(400);
        $this->report($version->getId(), [...$valid, 'engineVersion' => 0]);
        self::assertResponseStatusCodeSame(400);
        $this->report($version->getId(), [...$valid, 'errors' => [['kind' => 'error']]]);
        self::assertResponseStatusCodeSame(400);
        $this->report($version->getId(), [...$valid, 'variantUid' => 'nope']);
        self::assertResponseStatusCodeSame(404);
        $this->report('0000000000000000000000000000dead', $valid);
        self::assertResponseStatusCodeSame(404);
        self::assertSame([], $this->compatibility($game->getId()));
    }

    public function testOnlyAnAdminReports(): void
    {
        $game = $this->createGame('Majora', 'majora');
        $version = $this->storePack($game->getId());
        $valid = ['variantUid' => 'standard', 'status' => 'ok', 'errors' => [], 'unsupportedApis' => [], 'engineVersion' => 1];

        $this->report($version->getId(), $valid);
        self::assertResponseStatusCodeSame(401);
        $this->loginAs($this->createUser('member@example.org'));
        $this->report($version->getId(), $valid);
        self::assertResponseStatusCodeSame(403);
    }

    private function storePack(string $gameId): TrackerPackVersion
    {
        $manifest = TrackerPackManifest::fromArray([
            'package_uid' => 'MM_AP',
            'name' => 'Pack MM',
            'package_version' => '1.0',
            'variants' => [
                'standard' => ['display_name' => 'Maps and items', 'flags' => ['ap']],
                'itemsonly' => ['display_name' => 'Items only', 'flags' => ['ap']],
            ],
        ]);
        $now = new \DateTimeImmutable('2026-11-01T10:00:00+00:00');
        $packId = substr(hash('sha256', 'pack'), 0, 32);
        $versionId = substr(hash('sha256', 'version'), 0, 32);
        $version = TrackerPackVersion::record($versionId, $packId, $manifest, 'v1.0', 'https://github.com/o/r/archive/refs/tags/v1.0.zip', hash('sha256', 'zip'), 'k', 'Pack-1.0', 3, $now);
        $pack = TrackerPack::import($packId, $gameId, 'https://github.com/o/r', $manifest, 'MIT', $versionId, 'admin', $now);
        $this->entityManager->persist($version);
        $this->entityManager->persist($pack);
        $this->entityManager->flush();

        return $version;
    }

    /** @param array<string, mixed> $report */
    private function report(string $versionId, array $report): void
    {
        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/tracker-pack-versions/%s/compatibility', $versionId), $report);
    }

    /** @return list<array<mixed>> */
    private function compatibility(string $gameId): array
    {
        $this->client->request('GET', sprintf('/api/v1/admin/games/%s/tracker-packs', $gameId));
        self::assertResponseStatusCodeSame(200);
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);
        self::assertIsArray($data[0]);
        self::assertSame('Pack-1.0', $data[0]['rootPath']);
        $raw = $data[0]['compatibility'];
        self::assertIsArray($raw);
        $tries = [];
        foreach ($raw as $try) {
            self::assertIsArray($try);
            $tries[] = $try;
        }

        return $tries;
    }

    private function loginAsAdmin(): void
    {
        $this->loginAs($this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']));
    }
}
