<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\GameSelection\Domain\Entity\Game;
use App\GameSelection\Domain\Entity\GameCatalogSync;
use App\GameSelection\Infrastructure\Double\StubSteamWebApiClient;

/**
 * Story 11.5. A disabled game leaves every list where a player discovers or picks a game; its page stays
 * reachable by direct link, with the admin's message.
 */
final class DisabledGameHiddenTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        StubSteamWebApiClient::reset();
    }

    protected function tearDown(): void
    {
        StubSteamWebApiClient::reset();
        parent::tearDown();
    }

    public function testTheCatalogueLeavesADisabledGameOutOfItsListsAndTotals(): void
    {
        $this->createGame('Celeste', 'celeste');
        $this->disabled($this->createGame('Rogue Legacy', 'rogue-legacy'), 'Apworld en réparation.');

        foreach (['/api/v1/games', '/api/v1/games?all=1', '/api/v1/games?q=rogue', '/api/v1/games?page=1'] as $uri) {
            $this->client->jsonRequest('GET', $uri);
            self::assertResponseIsSuccessful();
            $response = $this->decodedJsonResponse();
            self::assertNotContains('rogue-legacy', $this->slugs($response['data'] ?? null), $uri);
            $meta = $response['meta'] ?? null;
            if (is_array($meta) && array_key_exists('total', $meta)) {
                self::assertSame(str_contains($uri, 'rogue') ? 0 : 1, $meta['total'], $uri);
            }
        }
    }

    public function testTheGamePageStaysReachableWithTheAdminsMessage(): void
    {
        $this->disabled($this->createGame('Rogue Legacy', 'rogue-legacy'), 'Apworld en réparation.');

        $this->client->jsonRequest('GET', '/api/v1/games/rogue-legacy');

        self::assertResponseIsSuccessful();
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);
        self::assertTrue($data['disabled']);
        self::assertSame('Apworld en réparation.', $data['disabledMessage']);
    }

    public function testSteamCouplingDoesNotCountADisabledGame(): void
    {
        $this->withSteamAppId($this->createGame('Hollow Knight', 'hollow-knight'), 367520);
        $this->withSteamAppId($this->disabled($this->createGame('Celeste', 'celeste'), null), 504230);
        StubSteamWebApiClient::$visibility = 'public';
        StubSteamWebApiClient::$ownedAppIds = [367520, 504230];

        $this->client->jsonRequest('POST', '/api/v1/games/steam-coupling', ['steamProfile' => '76561197960287930']);

        self::assertResponseIsSuccessful();
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);
        self::assertSame(1, $data['matchedCount']);
        self::assertSame(['hollow-knight'], $this->slugs($data['matchedGames']));
    }

    public function testADisabledFavouriteIsHiddenButKeptThroughASave(): void
    {
        $user = $this->createUser('fan@example.org', slug: 'fan');
        $celeste = $this->createGame('Celeste', 'celeste');
        $rogue = $this->createGame('Rogue Legacy', 'rogue-legacy');
        $hollow = $this->createGame('Hollow Knight', 'hollow-knight');
        $this->loginAs($user);
        $this->client->jsonRequest('PUT', '/api/v1/community/profile', ['audience' => 'public', 'favoriteGameIds' => [$celeste->getId(), $rogue->getId()]]);
        self::assertResponseIsSuccessful();
        $this->disabled($rogue, null);

        // Hidden from the editor and from the public profile.
        $this->client->jsonRequest('GET', '/api/v1/community/profile');
        self::assertSame(['celeste'], $this->slugs($this->data()['favoriteGames'] ?? null));
        $this->client->jsonRequest('GET', '/api/v1/community/profiles/fan');
        $customization = $this->data()['customization'] ?? null;
        self::assertIsArray($customization);
        self::assertSame(['celeste'], $this->slugs($customization['favoriteGames']));

        // The editor sends back what it shows: the disabled favourite must survive that save.
        $this->client->jsonRequest('PUT', '/api/v1/community/profile', ['favoriteGameIds' => [$celeste->getId(), $hollow->getId()]]);
        self::assertResponseIsSuccessful();
        $this->entityManager->getConnection()->executeStatement('UPDATE game SET disabled_at = NULL WHERE id = ?', [$rogue->getId()]);
        $this->client->jsonRequest('GET', '/api/v1/community/profile');
        self::assertSame(['celeste', 'hollow-knight', 'rogue-legacy'], $this->slugs($this->data()['favoriteGames'] ?? null));
    }

    public function testADisabledGameCannotBeAddedToFavouritesOrLists(): void
    {
        $this->loginAs($this->createUser('fan@example.org', slug: 'fan'));
        $rogue = $this->disabled($this->createGame('Rogue Legacy', 'rogue-legacy'), null);

        $this->client->jsonRequest('PUT', '/api/v1/community/profile', ['favoriteGameIds' => [$rogue->getId()]]);
        self::assertResponseStatusCodeSame(422);

        $this->client->jsonRequest('PUT', sprintf('/api/v1/me/game-lists/owned/%s', $rogue->getId()));
        self::assertResponseStatusCodeSame(422);
    }

    /**
     * @return array<mixed>
     */
    private function data(): array
    {
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);

        return $data;
    }

    private function disabled(Game $game, ?string $message): Game
    {
        $game->disable($message, new \DateTimeImmutable());
        $this->entityManager->flush();

        return $game;
    }

    private function withSteamAppId(Game $game, int $steamAppId): void
    {
        $this->entityManager->persist(new GameCatalogSync($game, igdbId: $steamAppId, steamAppId: $steamAppId));
        $this->entityManager->flush();
    }

    /**
     * @return list<string>
     */
    private function slugs(mixed $rows): array
    {
        self::assertIsArray($rows);
        $slugs = [];
        foreach ($rows as $row) {
            self::assertIsArray($row);
            self::assertIsString($row['slug'] ?? null);
            $slugs[] = $row['slug'];
        }

        return $slugs;
    }
}
