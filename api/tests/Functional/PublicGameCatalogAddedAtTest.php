<?php

declare(strict_types=1);

namespace App\Tests\Functional;

/** Story 28.17: each catalog game carries the date it was added, paginated or not. */
final class PublicGameCatalogAddedAtTest extends FunctionalTestCase
{
    public function testCatalogExposesAddedAt(): void
    {
        $this->createGame('Hollow Knight', 'hollow-knight');

        foreach (['/api/v1/games', '/api/v1/games?all=1'] as $url) {
            $this->client->jsonRequest('GET', $url);

            self::assertResponseIsSuccessful();
            $data = $this->decodedJsonResponse()['data'];
            self::assertIsArray($data);
            self::assertCount(1, $data);
            $game = $data[0];
            self::assertIsArray($game);
            self::assertSame('2026-05-01T10:00:00+00:00', $game['addedAt']);
        }
    }
}
