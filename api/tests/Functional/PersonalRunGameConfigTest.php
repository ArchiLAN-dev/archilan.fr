<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\GameSelection\Domain\ArchipelagoGame;
use App\Identity\Application\AuthSessionSigner;
use App\Identity\Domain\User;
use App\PersonalRuns\Domain\PersonalRun;
use App\PersonalRuns\Domain\PersonalRunParticipant;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;

final class PersonalRunGameConfigTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private AuthSessionSigner $authSessionSigner;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;

        $authSessionSigner = self::getContainer()->get(AuthSessionSigner::class);
        self::assertInstanceOf(AuthSessionSigner::class, $authSessionSigner);
        $this->authSessionSigner = $authSessionSigner;

        $metadata = [
            $this->entityManager->getClassMetadata(User::class),
            $this->entityManager->getClassMetadata(PersonalRun::class),
            $this->entityManager->getClassMetadata(PersonalRunParticipant::class),
            $this->entityManager->getClassMetadata(ArchipelagoGame::class),
        ];
        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    public function testConfigureGamesDraftRunReturns204(): void
    {
        $user = $this->createUser('alice@example.org');
        $game = $this->createGame('Hollow Knight');
        $run = $this->createRunDirectly($user->getId(), 'My Run', PersonalRun::STATUS_DRAFT);
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
        $game = $this->createGame('Celeste');
        $run = $this->createRunDirectly($user->getId(), 'Idle Run', PersonalRun::STATUS_IDLE);
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
        $game = $this->createGame('Super Metroid');
        $run = $this->createRunDirectly($user->getId(), 'Active Run', PersonalRun::STATUS_ACTIVE);
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
        $game = $this->createGame('Timespinner');
        $run = $this->createRunDirectly($user->getId(), 'Starting Run', PersonalRun::STATUS_STARTING);
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
        $run = $this->createRunDirectly($user->getId(), 'My Run', PersonalRun::STATUS_DRAFT);
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
        $game = $this->createGame('A Link to the Past');
        $run = $this->createRunDirectly($alice->getId(), 'Alice Run', PersonalRun::STATUS_DRAFT);
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
        $game = $this->createGame('Oracle of Seasons');
        $run = $this->createRunDirectly($alice->getId(), 'Alice Run', PersonalRun::STATUS_DRAFT);
        $participant = PersonalRunParticipant::create($run->getId(), $bob->getId(), new \DateTimeImmutable('2026-05-12T10:00:00+00:00'));
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
        $run = $this->createRunDirectly($user->getId(), 'My Run', PersonalRun::STATUS_DRAFT);
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
        $game = $this->createGame('Secret of Evermore');
        $run = $this->createRunDirectly($user->getId(), 'My Run', PersonalRun::STATUS_DRAFT);
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

    private function createUser(string $email): User
    {
        $now = new \DateTimeImmutable('2026-05-12T10:00:00+00:00');
        $user = new User(
            bin2hex(random_bytes(16)),
            $email,
            mb_strtolower($email),
            null,
            'test-hash',
            ['ROLE_USER'],
            $now,
            $now,
            $now,
        );

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function createGame(string $name): ArchipelagoGame
    {
        $game = ArchipelagoGame::create(
            $name,
            strtolower(str_replace(' ', '-', $name)),
            'A test game.',
            null,
            '',
            '',
            ArchipelagoGame::AVAILABILITY_AVAILABLE,
            new \DateTimeImmutable('2026-05-12T10:00:00+00:00'),
        );

        $this->entityManager->persist($game);
        $this->entityManager->flush();

        return $game;
    }

    private function createRunDirectly(string $ownerId, string $title, string $status): PersonalRun
    {
        $now = new \DateTimeImmutable('2026-05-12T10:00:00+00:00');
        $run = PersonalRun::create($ownerId, $title, $now);

        $reflection = new \ReflectionProperty(PersonalRun::class, 'status');
        $reflection->setValue($run, $status);

        $this->entityManager->persist($run);
        $this->entityManager->flush();

        return $run;
    }

    private function loginAs(User $user): void
    {
        $this->client->getCookieJar()->set(
            new Cookie(AuthSessionSigner::COOKIE_NAME, $this->authSessionSigner->sign($user->getId())),
        );
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
