<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\GameSelection\Domain\GameCatalogSync;
use App\PersonalRuns\Domain\Run;

final class PersonalRunGameSelectionPayloadTest extends FunctionalTestCase
{
    public function testAvailableGamesExposePlatformsAndSteamAppId(): void
    {
        $user = $this->createUser('alice@example.org');

        $metroid = $this->createGame('Super Metroid', 'super-metroid');
        $sync = new GameCatalogSync($metroid, steamAppId: 367520, platforms: [
            ['id' => 19, 'name' => 'Super Nintendo Entertainment System'],
            ['id' => 5, 'name' => 'Wii'],
        ]);
        $this->entityManager->persist($sync);

        $run = Run::create($user->getId(), 'My Run', new \DateTimeImmutable('2026-05-12T10:00:00+00:00'));
        $this->entityManager->persist($run);
        $this->entityManager->flush();

        $this->loginAs($user);

        $this->client->jsonRequest('GET', '/api/v1/runs/'.$run->getId().'/participants/me/game-selection');

        self::assertResponseIsSuccessful();
        $payload = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($payload);
        $available = $payload['availableGames'] ?? null;
        self::assertIsArray($available);

        $bySlug = [];
        foreach ($available as $item) {
            self::assertIsArray($item);
            $slug = $item['slug'] ?? null;
            self::assertIsString($slug);
            $bySlug[$slug] = $item;
        }

        self::assertArrayHasKey('super-metroid', $bySlug);
        self::assertSame(['Super Nintendo', 'Wii'], $bySlug['super-metroid']['platforms']);
        self::assertSame(367520, $bySlug['super-metroid']['steamAppId']);
    }
}
