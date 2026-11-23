<?php

declare(strict_types=1);

namespace App\Tests\Functional;

/**
 * Story 33.34: the API says which version it runs, with no login and no cache.
 */
final class VersionTest extends FunctionalTestCase
{
    public function testTheApiSaysItsVersion(): void
    {
        $this->client->request('GET', '/api/v1/version');

        self::assertResponseStatusCodeSame(200);
        $decoded = json_decode($this->client->getResponse()->getContent() ?: '', true);
        self::assertIsArray($decoded);
        $data = $decoded['data'] ?? null;
        self::assertIsArray($data);
        self::assertIsString($data['version'] ?? null);
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
    }
}
