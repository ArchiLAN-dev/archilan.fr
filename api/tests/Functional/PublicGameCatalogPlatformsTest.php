<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\GameSelection\Domain\GameCatalogSync;

final class PublicGameCatalogPlatformsTest extends FunctionalTestCase
{
    public function testCatalogExposesCuratedPlatformFamilies(): void
    {
        $metroid = $this->createGame('Super Metroid', 'super-metroid');
        $sync = new GameCatalogSync($metroid, platforms: [
            ['id' => 19, 'name' => 'Super Nintendo Entertainment System'],
            ['id' => 5, 'name' => 'Wii'],
            ['id' => 58, 'name' => 'Super Famicom'],
        ]);
        $this->entityManager->persist($sync);
        $this->createGame('No Platform Game', 'no-platform-game');
        $this->entityManager->flush();

        $this->client->jsonRequest('GET', '/api/v1/games?all=1');

        self::assertResponseIsSuccessful();
        $data = $this->decodedJsonResponse()['data'];
        self::assertIsArray($data);

        $bySlug = [];
        foreach ($data as $item) {
            self::assertIsArray($item);
            $slug = $item['slug'] ?? null;
            self::assertIsString($slug);
            $bySlug[$slug] = $item;
        }

        self::assertSame(['Super Nintendo', 'Wii'], $bySlug['super-metroid']['platforms']);
        self::assertSame([], $bySlug['no-platform-game']['platforms']);
    }
}
