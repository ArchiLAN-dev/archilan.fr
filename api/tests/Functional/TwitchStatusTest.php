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

        $this->assertResponseIsSuccessful();

        /** @var array{data: array{live: bool, viewerCount: int|null}} $body */
        $body = json_decode((string) $client->getResponse()->getContent(), true);

        $this->assertFalse($body['data']['live']);
        $this->assertNull($body['data']['viewerCount']);
    }

    public function testLiveStatusIsPublicAndRequiresNoAuthentication(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/live/status');

        $this->assertResponseStatusCodeSame(200);
    }
}
