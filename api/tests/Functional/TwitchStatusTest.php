<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class TwitchStatusTest extends WebTestCase
{
    public function testLiveStatusReturnsOfflineWhenNotConfigured(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/live/status');

        self::assertResponseIsSuccessful();

        /** @var array{data: array{live: bool, viewerCount: int|null}} $body */
        $body = json_decode((string) $client->getResponse()->getContent(), true);

        self::assertFalse($body['data']['live']);
        self::assertNull($body['data']['viewerCount']);
    }

    public function testLiveStatusIsPublicAndRequiresNoAuthentication(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/live/status');

        self::assertResponseStatusCodeSame(200);
    }
}
