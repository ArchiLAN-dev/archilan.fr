<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\GameSelection\Domain\GameCatalogSync;

final class PublicGameCatalogAllTest extends FunctionalTestCase
{
    public function testAllReturnsFullCatalogWithoutPagination(): void
    {
        $hollow = $this->createGame('Hollow Knight', 'hollow-knight');
        $sync = new GameCatalogSync($hollow, steamAppId: 367520);
        $this->entityManager->persist($sync);
        $this->createGame('Celeste', 'celeste');
        $this->createGame('Stardew Valley', 'stardew-valley');
        $this->entityManager->flush();

        $this->client->jsonRequest('GET', '/api/v1/games?all=1');

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertCount(3, $data);
        self::assertArrayNotHasKey('meta', $response);

        $bySlug = [];
        foreach ($data as $item) {
            self::assertIsArray($item);
            $slug = $item['slug'] ?? null;
            self::assertIsString($slug);
            $bySlug[$slug] = $item;
        }

        self::assertSame(367520, $bySlug['hollow-knight']['steamAppId']);
        self::assertNull($bySlug['celeste']['steamAppId']);
    }
}
