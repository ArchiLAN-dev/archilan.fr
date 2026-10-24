<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Realtime\Infrastructure\Double\SpyHub;

/**
 * Story 27.13: the bridge's raw result names the item of every location; only the admin topic may carry it.
 */
final class ReachablePushTest extends FunctionalTestCase
{
    public function testThePushPublishesARawAndTwoPlayerViews(): void
    {
        $hub = static::getContainer()->get(SpyHub::class);
        self::assertInstanceOf(SpyHub::class, $hub);
        $hub->reset();

        $this->client->request(
            'POST',
            '/api/v1/internal/sessions/s-1/slots/2/reachable-push',
            [],
            [],
            ['HTTP_X_INTERNAL_SECRET' => 'test-runner-secret', 'CONTENT_TYPE' => 'application/json'],
            json_encode([
                'reachable_unchecked' => [['id' => 1, 'name' => 'Chest', 'item' => ['id' => 9, 'name' => 'Hookshot']]],
                'spheres' => [['index' => 0, 'status' => 'current', 'locations' => [['id' => 1, 'name' => 'Chest', 'item' => ['id' => 9, 'name' => 'Hookshot']]]]],
            ], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(200);

        $byTopic = [];
        foreach ($hub->published as $update) {
            self::assertTrue($update->isPrivate());
            $topic = $update->getTopics()[0] ?? null;
            self::assertIsString($topic);
            $byTopic[$topic] = $update->getData();
        }

        self::assertSame(['runs/s-1/slots/2/reachable/full', 'runs/s-1/slots/2/reachable/spheres', 'runs/s-1/slots/2/reachable'], array_keys($byTopic));
        self::assertStringContainsString('Hookshot', $byTopic['runs/s-1/slots/2/reachable/full']);
        self::assertStringNotContainsString('Hookshot', $byTopic['runs/s-1/slots/2/reachable/spheres']);
        self::assertStringContainsString('spheres', $byTopic['runs/s-1/slots/2/reachable/spheres']);
        self::assertStringNotContainsString('Hookshot', $byTopic['runs/s-1/slots/2/reachable']);
        self::assertStringNotContainsString('spheres', $byTopic['runs/s-1/slots/2/reachable']);
    }

    public function testThePushNeedsTheInternalSecret(): void
    {
        $this->client->jsonRequest('POST', '/api/v1/internal/sessions/s-1/slots/2/reachable-push', []);

        self::assertResponseStatusCodeSame(401);
    }
}
