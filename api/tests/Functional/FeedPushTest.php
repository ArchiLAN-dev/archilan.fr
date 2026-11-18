<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Sessions\Domain\Entity\SessionFeedEvent;

final class FeedPushTest extends FunctionalTestCase
{
    private const string URI = '/api/v1/internal/sessions/run-feed-1/feed-push';
    private const string SECRET = 'test-runner-secret'; // matches CENTRAL_API_SECRET in .env.test

    public function testFeedPushRejectsMissingSecret(): void
    {
        $this->client->jsonRequest('POST', self::URI, ['type' => 'item_sent', 'text' => 'x']);
        self::assertResponseStatusCodeSame(401);
    }

    public function testFeedPushRejectsWrongSecret(): void
    {
        $this->client->jsonRequest(
            'POST',
            self::URI,
            ['type' => 'item_sent', 'text' => 'x'],
            ['HTTP_X_INTERNAL_SECRET' => 'nope'],
        );
        self::assertResponseStatusCodeSame(401);
    }

    public function testFeedPushAcceptsValidSecret(): void
    {
        $this->client->jsonRequest(
            'POST',
            self::URI,
            ['type' => 'item_sent', 'text' => 'Michel found Master Sword for Pierre'],
            ['HTTP_X_INTERNAL_SECRET' => self::SECRET],
        );
        self::assertResponseStatusCodeSame(200);

        $data = $this->decodedJsonResponse()['data'];
        self::assertIsArray($data);
        self::assertTrue($data['ok']);
    }

    public function testFeedPushKeepsArchipelagoIdsBeyondTheIntegerRange(): void
    {
        // Hotfix v0.70.2: some games number their items and locations past 2^31; the event failed to persist and
        // closed the entity manager for the rest of the push (bounties, bronze).
        $this->client->jsonRequest(
            'POST',
            self::URI,
            [
                'type' => 'item_sent',
                'text' => 'Michel found Big Key for Pierre',
                'item' => ['id' => 922026936107125, 'name' => 'Big Key', 'flags' => 1],
                'location' => ['id' => 6242624027, 'name' => 'Chest'],
                'sender' => ['slot' => 2, 'name' => 'Michel', 'game' => 'Game'],
                'receiver' => ['slot' => 3, 'name' => 'Pierre', 'game' => 'Game'],
            ],
            ['HTTP_X_INTERNAL_SECRET' => self::SECRET],
        );
        self::assertResponseStatusCodeSame(200);

        $event = $this->entityManager->getRepository(SessionFeedEvent::class)->findOneBy(['sessionId' => 'run-feed-1']);
        self::assertInstanceOf(SessionFeedEvent::class, $event);
        self::assertSame(922026936107125, $event->getItemId());
        self::assertSame(6242624027, $event->getLocationId());
    }
}
