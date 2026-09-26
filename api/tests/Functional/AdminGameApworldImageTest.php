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
}
