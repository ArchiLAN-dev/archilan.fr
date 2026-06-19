<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\GameSelection\Domain\Game;

final class GameContributionTest extends FunctionalTestCase
{
    private const STEPS = [['type' => 'apworld', 'title' => "Installer l'apworld", 'description' => 'd', 'links' => []]];

    public function testSubmitForUnavailableGameReturns404(): void
    {
        $this->createGame('Hidden', 'hidden', Game::AVAILABILITY_UNAVAILABLE);
        $this->loginAs($this->createUser('player@example.org', ['ROLE_USER']));

        $this->client->jsonRequest('POST', '/api/v1/game-contributions', [
            'gameSlug' => 'hidden',
            'steps' => self::STEPS,
        ]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testRejectsOverlongProposedName(): void
    {
        $this->loginAs($this->createUser('player@example.org', ['ROLE_USER']));

        $this->client->jsonRequest('POST', '/api/v1/game-contributions', [
            'proposedGameName' => str_repeat('a', 200),
            'steps' => self::STEPS,
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testAuthenticatedUserSubmitsForExistingGame(): void
    {
        $this->createGame('Hollow Knight', 'hollow-knight');
        $this->loginAs($this->createUser('player@example.org', ['ROLE_USER']));

        $this->client->jsonRequest('POST', '/api/v1/game-contributions', [
            'gameSlug' => 'hollow-knight',
            'steps' => self::STEPS,
            'message' => 'Petite correction',
        ]);
        self::assertResponseStatusCodeSame(201);

        $this->client->jsonRequest('GET', '/api/v1/game-contributions/me');
        self::assertResponseStatusCodeSame(200);
        $data = $this->decodedJsonResponse()['data'];
        self::assertIsArray($data);
        self::assertCount(1, $data);
        $row = $data[0];
        self::assertIsArray($row);
        self::assertSame('pending', $row['status']);
        self::assertSame('Hollow Knight', $row['target']);
        self::assertSame(1, $row['stepCount']);
    }

    public function testSubmitForNotListedGame(): void
    {
        $this->loginAs($this->createUser('player@example.org', ['ROLE_USER']));

        $this->client->jsonRequest('POST', '/api/v1/game-contributions', [
            'proposedGameName' => 'Un jeu pas encore listé',
            'steps' => self::STEPS,
        ]);
        self::assertResponseStatusCodeSame(201);

        $this->client->jsonRequest('GET', '/api/v1/game-contributions/me');
        $data = $this->decodedJsonResponse()['data'];
        self::assertIsArray($data);
        $row = $data[0];
        self::assertIsArray($row);
        self::assertSame('Un jeu pas encore listé', $row['target']);
    }

    public function testRejectsWhenBothOrNeitherTarget(): void
    {
        $this->createGame('Hollow Knight', 'hollow-knight');
        $this->loginAs($this->createUser('player@example.org', ['ROLE_USER']));

        $this->client->jsonRequest('POST', '/api/v1/game-contributions', [
            'gameSlug' => 'hollow-knight',
            'proposedGameName' => 'Autre',
            'steps' => self::STEPS,
        ]);
        self::assertResponseStatusCodeSame(422);

        $this->client->jsonRequest('POST', '/api/v1/game-contributions', ['steps' => self::STEPS]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testRejectsInvalidStepsAndUnknownGame(): void
    {
        $this->createGame('Hollow Knight', 'hollow-knight');
        $this->loginAs($this->createUser('player@example.org', ['ROLE_USER']));

        $this->client->jsonRequest('POST', '/api/v1/game-contributions', [
            'gameSlug' => 'hollow-knight',
            'steps' => [['type' => 'bogus', 'title' => 'x', 'links' => []]],
        ]);
        self::assertResponseStatusCodeSame(422);

        $this->client->jsonRequest('POST', '/api/v1/game-contributions', [
            'gameSlug' => 'does-not-exist',
            'steps' => self::STEPS,
        ]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testCapsPendingPerGame(): void
    {
        $this->createGame('Hollow Knight', 'hollow-knight');
        $this->loginAs($this->createUser('player@example.org', ['ROLE_USER']));

        $this->client->jsonRequest('POST', '/api/v1/game-contributions', ['gameSlug' => 'hollow-knight', 'steps' => self::STEPS]);
        self::assertResponseStatusCodeSame(201);

        $this->client->jsonRequest('POST', '/api/v1/game-contributions', ['gameSlug' => 'hollow-knight', 'steps' => self::STEPS]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testRequiresAuthentication(): void
    {
        $this->createGame('Hollow Knight', 'hollow-knight');
        $this->client->jsonRequest('POST', '/api/v1/game-contributions', ['gameSlug' => 'hollow-knight', 'steps' => self::STEPS]);
        self::assertResponseStatusCodeSame(401);

        $this->client->jsonRequest('GET', '/api/v1/game-contributions/me');
        self::assertResponseStatusCodeSame(401);
    }

    public function testMyContributionsIsolatedPerUser(): void
    {
        $this->createGame('Hollow Knight', 'hollow-knight');
        $this->loginAs($this->createUser('a@example.org', ['ROLE_USER']));
        $this->client->jsonRequest('POST', '/api/v1/game-contributions', ['gameSlug' => 'hollow-knight', 'steps' => self::STEPS]);

        $this->client->getCookieJar()->clear();
        $this->loginAs($this->createUser('b@example.org', ['ROLE_USER']));
        $this->client->jsonRequest('GET', '/api/v1/game-contributions/me');
        self::assertResponseStatusCodeSame(200);
        self::assertSame([], $this->decodedJsonResponse()['data']);
    }
}
