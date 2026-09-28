<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Sessions\Infrastructure\Double\NullRunnerGateway;

/**
 * Story 38.8 review: the admin game page gets the verdict's image and the image in use through the
 * real controller and serializer.
 */
final class AdminGameApworldImageTest extends FunctionalTestCase
{
    protected function tearDown(): void
    {
        NullRunnerGateway::reset();
        parent::tearDown();
    }

    public function testTheGamePageSaysAVerdictIsFromAnOlderImage(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $game = $this->createGame('Crystal Project', 'crystal-project');
        $game->configureApworld('h1.apworld', 'h1', 'Crystal Project', "game: Crystal Project\n", new \DateTimeImmutable());
        $this->entityManager->flush();
        NullRunnerGateway::$apworldPreflights = ['h1' => [
            'status' => 'passed', 'error' => '', 'checkedAt' => '2026-09-26T04:00:00Z', 'overridden' => false, 'blocks' => false,
            'image' => 'ghcr.io/archilan-dev/archipelago:0.16.0', 'imageId' => 'sha256:old',
        ]];
        NullRunnerGateway::$runtime = ['apImage' => 'ghcr.io/archilan-dev/archipelago:0.16.1', 'apImageId' => 'sha256:new'];

        $this->loginAs($admin);
        $this->client->request('GET', '/api/v1/admin/games/'.$game->getId());

        self::assertResponseIsSuccessful();
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);
        self::assertIsArray($data['apworldPreflight'] ?? null);
        self::assertSame('ghcr.io/archilan-dev/archipelago:0.16.0', $data['apworldPreflight']['image'] ?? null);
        self::assertSame(['apImage' => 'ghcr.io/archilan-dev/archipelago:0.16.1', 'apImageId' => 'sha256:new'], $data['archipelagoRuntime'] ?? null);
        self::assertFalse($data['apworldPreflightOnCurrentImage'] ?? null);
    }

    public function testTheGamePageShowsTheWarningOfAPass(): void
    {
        // Story 38.12: the admin sees what the generator reported on a passed test.
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $game = $this->createGame('Dragon Ball Z Budokai Tenkaichi 2', 'dbz-bt2');
        $game->configureApworld('h2.apworld', 'h2', 'Dragon Ball Z Budokai Tenkaichi 2', "game: Dragon Ball Z Budokai Tenkaichi 2\n", new \DateTimeImmutable());
        $this->entityManager->flush();
        NullRunnerGateway::$apworldPreflights = ['h2' => [
            'status' => 'passed', 'error' => '', 'checkedAt' => '2026-09-28T04:00:00Z', 'overridden' => false, 'blocks' => false,
            'image' => null, 'imageId' => null, 'warning' => 'Missing: [Discover: Evil Dragon]',
        ]];

        $this->loginAs($admin);
        $this->client->request('GET', '/api/v1/admin/games/'.$game->getId());

        self::assertResponseIsSuccessful();
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);
        self::assertIsArray($data['apworldPreflight'] ?? null);
        self::assertSame('Missing: [Discover: Evil Dragon]', $data['apworldPreflight']['warning'] ?? null);
    }
}
