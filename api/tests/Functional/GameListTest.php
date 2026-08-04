<?php

declare(strict_types=1);

namespace App\Tests\Functional;

/**
 * Story 28.13: the lists a player keeps on ArchiLAN, independent of any Steam coupling.
 */
final class GameListTest extends FunctionalTestCase
{
    /**
     * The game ids from the last response, narrowed - PHPStan will not take `data` on faith.
     *
     * @return list<string>
     */
    private function listedIds(): array
    {
        $data = $this->decodedJsonResponse()['data'];
        self::assertIsArray($data);

        $ids = [];
        foreach ($data as $id) {
            self::assertIsString($id);
            $ids[] = $id;
        }

        return $ids;
    }

    public function testTheListIsEmptyUntilAGameIsAdded(): void
    {
        $user = $this->createUser('alice@example.org');
        $this->loginAs($user);

        $this->client->jsonRequest('GET', '/api/v1/me/game-lists/owned');

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->listedIds());
    }

    public function testAddingThenRemovingAGame(): void
    {
        $user = $this->createUser('alice@example.org');
        $this->loginAs($user);
        $game = $this->createGame("Luigi's Mansion", 'luigis-mansion');

        $this->client->jsonRequest('PUT', sprintf('/api/v1/me/game-lists/owned/%s', $game->getId()));
        self::assertResponseIsSuccessful();

        $this->client->jsonRequest('GET', '/api/v1/me/game-lists/owned');
        self::assertSame([$game->getId()], $this->listedIds());

        $this->client->jsonRequest('DELETE', sprintf('/api/v1/me/game-lists/owned/%s', $game->getId()));
        self::assertResponseIsSuccessful();

        $this->client->jsonRequest('GET', '/api/v1/me/game-lists/owned');
        self::assertSame([], $this->listedIds());
    }

    /** Adding twice must not 500 on a duplicate key, and must not create a second row. */
    public function testAddingIsIdempotent(): void
    {
        $user = $this->createUser('alice@example.org');
        $this->loginAs($user);
        $game = $this->createGame('Super Metroid', 'super-metroid');

        $this->client->jsonRequest('PUT', sprintf('/api/v1/me/game-lists/owned/%s', $game->getId()));
        self::assertResponseIsSuccessful();
        $this->client->jsonRequest('PUT', sprintf('/api/v1/me/game-lists/owned/%s', $game->getId()));
        self::assertResponseIsSuccessful();

        $this->client->jsonRequest('GET', '/api/v1/me/game-lists/owned');
        self::assertSame([$game->getId()], $this->listedIds());
    }

    /** Removing something never added is a no-op, not a 404. */
    public function testRemovingIsIdempotent(): void
    {
        $user = $this->createUser('alice@example.org');
        $this->loginAs($user);
        $game = $this->createGame('TUNIC', 'tunic');

        $this->client->jsonRequest('DELETE', sprintf('/api/v1/me/game-lists/owned/%s', $game->getId()));

        self::assertResponseIsSuccessful();
    }

    public function testAddingAnUnknownGameReturns404(): void
    {
        $user = $this->createUser('alice@example.org');
        $this->loginAs($user);

        $this->client->jsonRequest('PUT', '/api/v1/me/game-lists/owned/doesnotexist');

        self::assertResponseStatusCodeSame(404);
    }

    /** An unknown kind is a 404 rather than an empty list: a typo must not look like an empty shelf. */
    public function testAnUnknownListKindReturns404(): void
    {
        $user = $this->createUser('alice@example.org');
        $this->loginAs($user);
        $game = $this->createGame('Hollow Knight', 'hollow-knight');

        $this->client->jsonRequest('GET', '/api/v1/me/game-lists/wishlist');
        self::assertResponseStatusCodeSame(404);

        $this->client->jsonRequest('PUT', sprintf('/api/v1/me/game-lists/wishlist/%s', $game->getId()));
        self::assertResponseStatusCodeSame(404);

        $this->client->jsonRequest('DELETE', sprintf('/api/v1/me/game-lists/wishlist/%s', $game->getId()));
        self::assertResponseStatusCodeSame(404);
    }

    public function testTheListIsPerPlayer(): void
    {
        $alice = $this->createUser('alice@example.org');
        $bob = $this->createUser('bob@example.org');
        $game = $this->createGame('Celeste', 'celeste');

        $this->loginAs($alice);
        $this->client->jsonRequest('PUT', sprintf('/api/v1/me/game-lists/owned/%s', $game->getId()));
        self::assertResponseIsSuccessful();

        $this->loginAs($bob);
        $this->client->jsonRequest('GET', '/api/v1/me/game-lists/owned');
        self::assertSame([], $this->listedIds(), 'Bob does not own the games Alice marked');
    }

    public function testAuthenticationIsRequired(): void
    {
        $this->client->jsonRequest('GET', '/api/v1/me/game-lists/owned');
        self::assertResponseStatusCodeSame(401);

        $this->client->jsonRequest('PUT', '/api/v1/me/game-lists/owned/whatever');
        self::assertResponseStatusCodeSame(401);

        $this->client->jsonRequest('DELETE', '/api/v1/me/game-lists/owned/whatever');
        self::assertResponseStatusCodeSame(401);
    }
}
