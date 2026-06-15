<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\GameSelection\Domain\GameCatalogSync;

final class PublicGameCatalogSteamAppIdTest extends FunctionalTestCase
{
    public function testPublicCatalogExposesSteamAppId(): void
    {
        $hollow = $this->createGame('Hollow Knight', 'hollow-knight');
        $sync = new GameCatalogSync($hollow, igdbId: 1234, steamAppId: 367520);
        $this->entityManager->persist($sync);

        // A game without any catalog sync row → steamAppId must be null.
        $this->createGame('Zelda', 'zelda');
        $this->entityManager->flush();

        $this->client->jsonRequest('GET', '/api/v1/games');

        self::assertResponseIsSuccessful();
        $data = $this->decodedJsonResponse()['data'];
        self::assertIsArray($data);

        $bySlug = [];
        foreach ($data as $item) {
            self::assertIsArray($item);
            self::assertArrayHasKey('steamAppId', $item);
            $slug = $item['slug'] ?? null;
            self::assertIsString($slug);
            $bySlug[$slug] = $item;
        }

        self::assertSame(367520, $bySlug['hollow-knight']['steamAppId']);
        self::assertNull($bySlug['zelda']['steamAppId']);
    }
}
