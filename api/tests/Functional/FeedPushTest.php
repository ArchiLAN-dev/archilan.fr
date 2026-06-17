<?php

declare(strict_types=1);

namespace App\Tests\Functional;

final class FeedPushTest extends FunctionalTestCase
{
    private const URI = '/api/v1/internal/sessions/run-feed-1/feed-push';
    private const SECRET = 'test-runner-secret'; // matches CENTRAL_API_SECRET in .env.test

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
}
