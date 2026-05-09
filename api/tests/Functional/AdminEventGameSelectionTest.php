<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Events\Domain\Event;
use App\GameSelection\Domain\ArchipelagoGame;
use App\Identity\Application\AuthSessionSigner;
use App\Identity\Domain\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;

final class AdminEventGameSelectionTest extends WebTestCase
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
            $this->entityManager->getClassMetadata(Event::class),
            $this->entityManager->getClassMetadata(ArchipelagoGame::class),
        ];
        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    public function testAnonymousAndLambdaCannotConfigureGameSelection(): void
    {
        $this->client->jsonRequest('GET', '/api/v1/admin/events/nonexistent/game-selection');
        self::assertResponseStatusCodeSame(401);

        $lambda = $this->createUser('lambda@example.org', ['ROLE_USER']);
        $this->loginAs($lambda);

        $this->client->jsonRequest('GET', '/api/v1/admin/events/nonexistent/game-selection');
        self::assertResponseStatusCodeSame(403);

        $this->client->jsonRequest('PATCH', '/api/v1/admin/events/nonexistent/game-selection', [
            'gameSelectionEnabled' => true,
            'games' => [],
        ]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminGetsNotFoundForUnknownEvent(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', '/api/v1/admin/events/nonexistent/game-selection');
        self::assertResponseStatusCodeSame(404);

        $this->client->jsonRequest('PATCH', '/api/v1/admin/events/nonexistent/game-selection', [
            'gameSelectionEnabled' => false,
            'games' => [],
        ]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testAdminGetsDefaultDisabledConfigWithAvailableGames(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);

        $eventId = $this->createEvent();
        $gameId = $this->createGame('Zelda OoT', 'zelda-oot');

        $this->client->jsonRequest('GET', sprintf('/api/v1/admin/events/%s/game-selection', $eventId));

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertFalse($data['gameSelectionEnabled']);
        self::assertSame([], $data['selectedGames']);
        $availableGames = $data['availableGames'];
        self::assertIsArray($availableGames);
        self::assertCount(1, $availableGames);
        $firstGame = $availableGames[0];
        self::assertIsArray($firstGame);
        self::assertSame($gameId, $firstGame['id']);
    }

    public function testAdminOnlyGetsAvailableAndExperimentalGames(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);

        $eventId = $this->createEvent();
        $this->createGame('Zelda OoT', 'zelda-oot', ArchipelagoGame::AVAILABILITY_AVAILABLE);
        $this->createGame('Factorio', 'factorio', ArchipelagoGame::AVAILABILITY_EXPERIMENTAL);
        $this->createGame('Celeste', 'celeste', ArchipelagoGame::AVAILABILITY_UNAVAILABLE);

        $this->client->jsonRequest('GET', sprintf('/api/v1/admin/events/%s/game-selection', $eventId));

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        $availableGames = $data['availableGames'];
        self::assertIsArray($availableGames);
        self::assertCount(2, $availableGames);
        self::assertNotContains(
            ArchipelagoGame::AVAILABILITY_UNAVAILABLE,
            array_column($availableGames, 'availability'),
        );
    }

    public function testAdminEnablesGameSelectionWithGames(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);

        $eventId = $this->createEvent();
        $gameId = $this->createGame('Zelda OoT', 'zelda-oot');

        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/events/%s/game-selection', $eventId), [
            'gameSelectionEnabled' => true,
            'games' => [
                ['gameId' => $gameId],
            ],
        ]);

        self::assertResponseIsSuccessful();

        $this->client->jsonRequest('GET', sprintf('/api/v1/admin/events/%s/game-selection', $eventId));
        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertTrue($data['gameSelectionEnabled']);
        $selectedGames = $data['selectedGames'];
        self::assertIsArray($selectedGames);
        self::assertCount(1, $selectedGames);
        $selectedGame = $selectedGames[0];
        self::assertIsArray($selectedGame);
        self::assertSame($gameId, $selectedGame['gameId']);
        self::assertSame('Zelda OoT', $selectedGame['gameName']);
    }

    public function testAdminDisablesGameSelectionPreservingConfig(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);

        $eventId = $this->createEvent();
        $gameId = $this->createGame('Zelda OoT', 'zelda-oot');

        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/events/%s/game-selection', $eventId), [
            'gameSelectionEnabled' => true,
            'games' => [['gameId' => $gameId]],
        ]);
        self::assertResponseIsSuccessful();

        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/events/%s/game-selection', $eventId), [
            'gameSelectionEnabled' => false,
            'games' => [],
        ]);
        self::assertResponseIsSuccessful();

        $this->client->jsonRequest('GET', sprintf('/api/v1/admin/events/%s/game-selection', $eventId));
        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertFalse($data['gameSelectionEnabled']);
    }

    public function testGameSelectionEnabledIsRequired(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);

        $eventId = $this->createEvent();

        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/events/%s/game-selection', $eventId), [
            'games' => [],
        ]);

        self::assertResponseStatusCodeSame(422);
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['error']);
        self::assertIsArray($response['error']['details']);
        self::assertArrayHasKey('gameSelectionEnabled', $response['error']['details']);
    }

    public function testInvalidGameIdIsRejected(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);

        $eventId = $this->createEvent();

        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/events/%s/game-selection', $eventId), [
            'gameSelectionEnabled' => true,
            'games' => [
                ['gameId' => 'nonexistent-game-id'],
            ],
        ]);

        self::assertResponseStatusCodeSame(422);
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['error']);
        self::assertIsArray($response['error']['details']);
        self::assertArrayHasKey('games.0.gameId', $response['error']['details']);
    }

    public function testUnavailableGameIsRejectedFromSelection(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);

        $eventId = $this->createEvent();
        $gameId = $this->createGame('Factorio', 'factorio', ArchipelagoGame::AVAILABILITY_UNAVAILABLE);

        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/events/%s/game-selection', $eventId), [
            'gameSelectionEnabled' => true,
            'games' => [
                ['gameId' => $gameId],
            ],
        ]);

        self::assertResponseStatusCodeSame(422);
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['error']);
        self::assertIsArray($response['error']['details']);
        self::assertArrayHasKey('games.0.gameId', $response['error']['details']);
    }

    public function testDuplicateGameIdIsRejected(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);

        $eventId = $this->createEvent();
        $gameId = $this->createGame('Zelda OoT', 'zelda-oot');

        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/events/%s/game-selection', $eventId), [
            'gameSelectionEnabled' => true,
            'games' => [
                ['gameId' => $gameId],
                ['gameId' => $gameId],
            ],
        ]);

        self::assertResponseStatusCodeSame(422);
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['error']);
        self::assertIsArray($response['error']['details']);
        self::assertArrayHasKey('games.1.gameId', $response['error']['details']);
    }

    public function testGameSelectionEnabledReflectedInEventList(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);

        $eventId = $this->createEvent();
        $gameId = $this->createGame('Zelda OoT', 'zelda-oot');

        $this->client->jsonRequest('GET', '/api/v1/admin/events');
        $list = $this->decodedJsonResponse();
        $listData = $list['data'];
        self::assertIsArray($listData);
        self::assertCount(1, $listData);
        $firstEvent = $listData[0];
        self::assertIsArray($firstEvent);
        self::assertFalse($firstEvent['gameSelectionEnabled']);

        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/events/%s/game-selection', $eventId), [
            'gameSelectionEnabled' => true,
            'games' => [['gameId' => $gameId]],
        ]);
        self::assertResponseIsSuccessful();

        $this->client->jsonRequest('GET', '/api/v1/admin/events');
        $list = $this->decodedJsonResponse();
        $listData2 = $list['data'];
        self::assertIsArray($listData2);
        $updatedEvent = $listData2[0];
        self::assertIsArray($updatedEvent);
        self::assertTrue($updatedEvent['gameSelectionEnabled']);
    }

    private function createEvent(): string
    {
        $this->client->jsonRequest('POST', '/api/v1/admin/events', [
            'title' => 'Spring Sync 2027',
            'description' => 'Une session Archipelago de printemps.',
            'type' => 'Multiworld ouvert',
            'startsAt' => '2027-05-31T10:00:00+00:00',
            'endsAt' => '2027-05-31T22:00:00+00:00',
            'venue' => 'Clermont-Ferrand',
            'capacity' => 48,
            'registrationOpensAt' => '2027-05-01T10:00:00+00:00',
            'registrationClosesAt' => '2027-05-30T18:00:00+00:00',
            'isPublic' => true,
        ]);
        self::assertResponseStatusCodeSame(201);
        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertIsString($data['id']);

        return $data['id'];
    }

    private function createGame(
        string $name,
        string $slug,
        string $availability = ArchipelagoGame::AVAILABILITY_AVAILABLE,
    ): string {
        $this->client->jsonRequest('POST', '/api/v1/admin/games', [
            'name' => $name,
            'slug' => $slug,
            'description' => 'Un jeu compatible Archipelago.',
            'coverImageAlt' => 'Logo '.$name,
            'coverImageCredit' => 'Publisher',
            'availability' => $availability,
        ]);
        self::assertResponseStatusCodeSame(201);
        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertIsString($data['id']);

        return $data['id'];
    }

    /**
     * @param list<string> $roles
     */
    private function createUser(string $email, array $roles): User
    {
        $now = new \DateTimeImmutable('2026-04-30T10:00:00+00:00');
        $user = new User(
            bin2hex(random_bytes(16)),
            $email,
            mb_strtolower($email),
            null,
            'test-password-hash',
            $roles,
            $now,
            $now,
            $now,
        );

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function loginAs(User $user): void
    {
        $this->client->getCookieJar()->set(
            new Cookie(AuthSessionSigner::COOKIE_NAME, $this->authSessionSigner->sign($user->getId())),
        );
    }

    /**
     * @return array<mixed>
     */
    private function decodedJsonResponse(): array
    {
        $decoded = json_decode($this->client->getResponse()->getContent() ?: '', true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
