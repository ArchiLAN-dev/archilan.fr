<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\PersonalRuns\Domain\Run;
use App\PersonalRuns\Domain\RunParticipant;

final class PersonalRunGameConfigTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    public function testConfigureGamesDraftRunReturns204(): void
    {
        $user = $this->createUser('alice@example.org');
        $game = $this->createGame('Hollow Knight', 'hollow-knight');
        $run = $this->createRunDirectly($user->getId(), 'My Run', Run::STATUS_DRAFT);
        $this->loginAs($user);

        $this->client->jsonRequest('PATCH', '/api/v1/runs/'.$run->getId().'/games', [
            'games' => [['gameId' => $game->getId()]],
        ]);

        self::assertResponseStatusCodeSame(204);

        $this->entityManager->refresh($run);
        self::assertSame([['gameId' => $game->getId()]], $run->getGameSelectionConfig());
    }

    public function testConfigureGamesIdleRunReturns204(): void
    {
        $user = $this->createUser('alice@example.org');
        $game = $this->createGame('Celeste', 'celeste');
        $run = $this->createRunDirectly($user->getId(), 'Idle Run', Run::STATUS_IDLE);
        $this->loginAs($user);

        $this->client->jsonRequest('PATCH', '/api/v1/runs/'.$run->getId().'/games', [
            'games' => [['gameId' => $game->getId()]],
        ]);

        self::assertResponseStatusCodeSame(204);

        $this->entityManager->refresh($run);
        self::assertSame([['gameId' => $game->getId()]], $run->getGameSelectionConfig());
    }

    public function testConfigureGamesActiveRunReturns422(): void
    {
        $user = $this->createUser('alice@example.org');
        $game = $this->createGame('Super Metroid', 'super-metroid');
        $run = $this->createRunDirectly($user->getId(), 'Active Run', Run::STATUS_ACTIVE);
        $this->loginAs($user);

        $this->client->jsonRequest('PATCH', '/api/v1/runs/'.$run->getId().'/games', [
            'games' => [['gameId' => $game->getId()]],
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('run_active', $this->errorCode());
    }

    public function testConfigureGamesStartingRunReturns422(): void
    {
        $user = $this->createUser('alice@example.org');
        $game = $this->createGame('Timespinner', 'timespinner');
        $run = $this->createRunDirectly($user->getId(), 'Starting Run', Run::STATUS_STARTING);
        $this->loginAs($user);

        $this->client->jsonRequest('PATCH', '/api/v1/runs/'.$run->getId().'/games', [
            'games' => [['gameId' => $game->getId()]],
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('run_active', $this->errorCode());
    }

    public function testConfigureGamesUnknownGameIdReturns422(): void
    {
        $user = $this->createUser('alice@example.org');
        $run = $this->createRunDirectly($user->getId(), 'My Run', Run::STATUS_DRAFT);
        $this->loginAs($user);

        $this->client->jsonRequest('PATCH', '/api/v1/runs/'.$run->getId().'/games', [
            'games' => [['gameId' => bin2hex(random_bytes(16))]],
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('unknown_game', $this->errorCode());
        $details = $this->errorDetails();
        self::assertArrayHasKey('games.0.gameId', $details);
    }

    public function testConfigureGamesNonOwnerReturns403(): void
    {
        $alice = $this->createUser('alice@example.org');
        $bob = $this->createUser('bob@example.org');
        $game = $this->createGame('A Link to the Past', 'a-link-to-the-past');
        $run = $this->createRunDirectly($alice->getId(), 'Alice Run', Run::STATUS_DRAFT);
        $this->loginAs($bob);

        $this->client->jsonRequest('PATCH', '/api/v1/runs/'.$run->getId().'/games', [
            'games' => [['gameId' => $game->getId()]],
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testConfigureGamesParticipantReturns403(): void
    {
        $alice = $this->createUser('alice@example.org');
        $bob = $this->createUser('bob@example.org');
        $game = $this->createGame('Oracle of Seasons', 'oracle-of-seasons');
        $run = $this->createRunDirectly($alice->getId(), 'Alice Run', Run::STATUS_DRAFT);
        $participant = RunParticipant::create($run->getId(), $bob->getId(), new \DateTimeImmutable('2026-05-12T10:00:00+00:00'));
        $this->entityManager->persist($participant);
        $this->entityManager->flush();
        $this->loginAs($bob);

        $this->client->jsonRequest('PATCH', '/api/v1/runs/'.$run->getId().'/games', [
            'games' => [['gameId' => $game->getId()]],
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testConfigureGamesEmptyListReturns422(): void
    {
        $user = $this->createUser('alice@example.org');
        $run = $this->createRunDirectly($user->getId(), 'My Run', Run::STATUS_DRAFT);
        $this->loginAs($user);

        $this->client->jsonRequest('PATCH', '/api/v1/runs/'.$run->getId().'/games', [
            'games' => [],
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('games_required', $this->errorCode());
        $details = $this->errorDetails();
        self::assertArrayHasKey('games', $details);
    }

    public function testConfigureGamesMalformedEntryReturns422(): void
    {
        $user = $this->createUser('alice@example.org');
        $game = $this->createGame('Secret of Evermore', 'secret-of-evermore');
        $run = $this->createRunDirectly($user->getId(), 'My Run', Run::STATUS_DRAFT);
        $this->loginAs($user);

        $this->client->jsonRequest('PATCH', '/api/v1/runs/'.$run->getId().'/games', [
            'games' => [['foo' => 'bar'], ['gameId' => $game->getId()]],
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('game_id_required', $this->errorCode());
        $details = $this->errorDetails();
        self::assertArrayHasKey('games.0.gameId', $details);

        $this->entityManager->refresh($run);
        self::assertNull($run->getGameSelectionConfig());
    }

    public function testConfigureGamesUnauthenticatedReturns401(): void
    {
        $this->client->jsonRequest('PATCH', '/api/v1/runs/'.bin2hex(random_bytes(16)).'/games', [
            'games' => [['gameId' => 'anything']],
        ]);

        self::assertResponseStatusCodeSame(401);
    }

    public function testConfigureGamesNonExistentRunReturns404(): void
    {
        $user = $this->createUser('alice@example.org');
        $this->loginAs($user);

        $this->client->jsonRequest('PATCH', '/api/v1/runs/'.bin2hex(random_bytes(16)).'/games', [
            'games' => [['gameId' => 'anything']],
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    private function createRunDirectly(string $ownerId, string $title, string $status): Run
    {
        $now = new \DateTimeImmutable('2026-05-12T10:00:00+00:00');
        $run = Run::create($ownerId, $title, $now);

        $reflection = new \ReflectionProperty(Run::class, 'status');
        $reflection->setValue($run, $status);

        $this->entityManager->persist($run);
        $this->entityManager->flush();

        return $run;
    }

    private function errorCode(): string
    {
        $decoded = $this->decodedResponse();
        $error = $decoded['error'] ?? null;
        self::assertIsArray($error);
        $code = $error['code'] ?? null;
        self::assertIsString($code);

        return $code;
    }

    /**
     * @return array<string, mixed>
     */
    private function errorDetails(): array
    {
        $decoded = $this->decodedResponse();
        $error = $decoded['error'] ?? null;
        self::assertIsArray($error);
        $details = $error['details'] ?? null;
        self::assertIsArray($details);

        $result = [];
        foreach ($details as $key => $value) {
            self::assertIsString($key);
            $result[$key] = $value;
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodedResponse(): array
    {
        $content = $this->client->getResponse()->getContent() ?: '';
        $decoded = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        $result = [];
        foreach ($decoded as $key => $value) {
            self::assertIsString($key);
            $result[$key] = $value;
        }

        return $result;
    }
}
