<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\GameSelection\Domain\Game;
use App\GameSelection\Domain\GameCatalogSync;
use App\Identity\Domain\User;
use App\Registrations\Domain\Registration;
use App\Sessions\Domain\Session;
use App\Sessions\Domain\SessionSlot;
use App\Sessions\Infrastructure\NullRunnerGateway;
use Doctrine\ORM\Tools\SchemaTool;

final class RunnerValidatePipelineTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $metadata = [
            $this->entityManager->getClassMetadata(User::class),
            $this->entityManager->getClassMetadata(Session::class),
            $this->entityManager->getClassMetadata(SessionSlot::class),
            $this->entityManager->getClassMetadata(Registration::class),
            $this->entityManager->getClassMetadata(Game::class),
            $this->entityManager->getClassMetadata(GameCatalogSync::class),
        ];
        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    // ─── Auth ─────────────────────────────────────────────────────────────────

    public function testValidateEndpointRequiresAdmin(): void
    {
        $player = $this->createUser('player@example.org', ['ROLE_USER'], 'Player');
        $this->loginAs($player);
        $session = $this->persistSession('evt-001');

        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/sessions/%s/validate', $session->getId()));

        self::assertResponseStatusCodeSame(403);
    }

    public function testValidateEndpointReturns404ForUnknownSession(): void
    {
        $admin = $this->createAdmin();
        $this->loginAs($admin);

        $this->client->jsonRequest('POST', '/api/v1/admin/sessions/no-such-session/validate');

        self::assertResponseStatusCodeSame(404);
    }

    // ─── Happy path ───────────────────────────────────────────────────────────

    public function testValidateTransitionsToReady(): void
    {
        $admin = $this->createAdmin();
        $this->loginAs($admin);

        $player = $this->createUser('alice@example.org', ['ROLE_USER'], 'Alice');
        $game = $this->makeGame('Hollow Knight', 'Hollow Knight');
        $slotId = 'slot-hk-1';
        $reg = $this->createRegistrationWithYaml(
            $player->getId(), 'evt-001', $game->getId(), $slotId,
            "name: Alice\ngame: Hollow Knight\n",
        );
        $session = $this->persistSession('evt-001');
        $this->persistSessionSlot($session->getId(), $reg->getId(), $game->getId(), 'placeholder', 0, $slotId);

        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/sessions/%s/validate', $session->getId()));

        self::assertResponseIsSuccessful();
        $data = $this->decodedJsonResponse()['data'];
        self::assertIsArray($data);
        self::assertSame('ready', $data['status']);
    }

    public function testValidateUpdatesSlotNamesInDb(): void
    {
        $admin = $this->createAdmin();
        $this->loginAs($admin);

        $alice = $this->createUser('alice@example.org', ['ROLE_USER'], 'Alice');
        $bob = $this->createUser('bob@example.org', ['ROLE_USER'], 'Bob');
        $game = $this->makeGame('Hollow Knight', 'Hollow Knight');
        $slotA = 'slot-a';
        $slotB = 'slot-b';
        $regAlice = $this->createRegistrationWithYaml($alice->getId(), 'evt-001', $game->getId(), $slotA, 'yaml: a');
        $regBob = $this->createRegistrationWithYaml($bob->getId(), 'evt-001', $game->getId(), $slotB, 'yaml: b');
        $session = $this->persistSession('evt-001');
        $this->persistSessionSlot($session->getId(), $regAlice->getId(), $game->getId(), 'old-name-a', 0, $slotA);
        $this->persistSessionSlot($session->getId(), $regBob->getId(), $game->getId(), 'old-name-b', 1, $slotB);

        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/sessions/%s/validate', $session->getId()));

        self::assertResponseIsSuccessful();

        $this->entityManager->clear();
        /** @var list<SessionSlot> $slots */
        $slots = $this->entityManager->getRepository(SessionSlot::class)
            ->findBy(['sessionId' => $session->getId()], ['slotOrder' => 'ASC']);
        self::assertCount(2, $slots);
        self::assertSame('Alice_HK', $slots[0]->getSlotName());
        self::assertSame('Bob_HK', $slots[1]->getSlotName());
    }

    // ─── Default YAML fallback (story 12.2) ────────────────────────────────────

    public function testValidateUsesGameDefaultYamlWhenSlotHasNoSavedYaml(): void
    {
        NullRunnerGateway::reset();

        $admin = $this->createAdmin();
        $this->loginAs($admin);

        $defaultYaml = "name: '{{ player }}'\ngame: Hollow Knight\nHollow Knight: {}\n";
        $player = $this->createUser('alice@example.org', ['ROLE_USER'], 'Alice');
        $game = $this->makeGameWithDefaultYaml('Hollow Knight', 'Hollow Knight', $defaultYaml);
        $slotId = 'slot-hk-1';
        $reg = $this->createRegistrationWithoutYaml($player->getId(), 'evt-001', $game->getId(), $slotId);
        $session = $this->persistSession('evt-001');
        $this->persistSessionSlot($session->getId(), $reg->getId(), $game->getId(), 'placeholder', 0, $slotId);

        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/sessions/%s/validate', $session->getId()));

        self::assertResponseIsSuccessful();
        self::assertIsArray(NullRunnerGateway::$lastConfigureSlots);
        self::assertCount(1, NullRunnerGateway::$lastConfigureSlots);
        self::assertSame($defaultYaml, NullRunnerGateway::$lastConfigureSlots[0]['playerYaml']);
    }

    public function testValidateKeepsSavedYamlWhenSlotIsConfigured(): void
    {
        NullRunnerGateway::reset();

        $admin = $this->createAdmin();
        $this->loginAs($admin);

        $savedYaml = "name: Alice\ngame: Hollow Knight\nHollow Knight: { custom: true }\n";
        $player = $this->createUser('alice@example.org', ['ROLE_USER'], 'Alice');
        $game = $this->makeGameWithDefaultYaml('Hollow Knight', 'Hollow Knight', "name: default\n");
        $slotId = 'slot-hk-1';
        $reg = $this->createRegistrationWithYaml($player->getId(), 'evt-001', $game->getId(), $slotId, $savedYaml);
        $session = $this->persistSession('evt-001');
        $this->persistSessionSlot($session->getId(), $reg->getId(), $game->getId(), 'placeholder', 0, $slotId);

        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/sessions/%s/validate', $session->getId()));

        self::assertResponseIsSuccessful();
        self::assertIsArray(NullRunnerGateway::$lastConfigureSlots);
        self::assertSame($savedYaml, NullRunnerGateway::$lastConfigureSlots[0]['playerYaml']);
    }

    public function testValidateReturns409WhenSessionNotDraft(): void
    {
        $admin = $this->createAdmin();
        $this->loginAs($admin);

        $session = $this->persistSession('evt-001');
        $this->patchStatus($session->getId(), 'validating');

        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/sessions/%s/validate', $session->getId()));

        self::assertResponseStatusCodeSame(409);
    }

    public function testValidateIsIdempotentWhenSessionAlreadyReady(): void
    {
        $admin = $this->createAdmin();
        $this->loginAs($admin);

        $session = $this->persistSession('evt-001');
        $this->patchStatus($session->getId(), 'validating');
        $this->patchStatus($session->getId(), 'ready');

        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/sessions/%s/validate', $session->getId()));

        self::assertResponseIsSuccessful();
        $data = $this->decodedJsonResponse()['data'];
        self::assertIsArray($data);
        self::assertSame('ready', $data['status']);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function createAdmin(): User
    {
        return $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
    }

    private function makeGame(string $name, ?string $archipelagoGameName): Game
    {
        $slug = strtolower(str_replace(' ', '-', $name)).'-'.bin2hex(random_bytes(2));
        $game = $this->createGame($name, $slug);
        if (null !== $archipelagoGameName) {
            $now = new \DateTimeImmutable('2026-05-05T10:00:00+00:00');
            $game->configureApworld('test-key', 'test-hash', $archipelagoGameName, '', $now);
            $this->entityManager->flush();
        }

        return $game;
    }

    private function makeGameWithDefaultYaml(string $name, string $archipelagoGameName, string $defaultYaml): Game
    {
        $slug = strtolower(str_replace(' ', '-', $name)).'-'.bin2hex(random_bytes(2));
        $game = $this->createGame($name, $slug);
        $now = new \DateTimeImmutable('2026-05-05T10:00:00+00:00');
        $game->configureApworld('test-key', 'test-hash', $archipelagoGameName, $defaultYaml, $now);
        $this->entityManager->flush();

        return $game;
    }

    private function createRegistrationWithYaml(
        string $userId,
        string $eventId,
        string $gameId,
        string $slotId,
        string $playerYaml,
    ): Registration {
        $now = new \DateTimeImmutable('2026-05-05T10:00:00+00:00');
        $reg = $this->createRegistration($eventId, $userId);
        $reg->replaceSlots([['slotId' => $slotId, 'gameId' => $gameId]], $now);
        $reg->setSlotPlayerYaml($slotId, $playerYaml, 'test-hash', $now);
        $this->entityManager->flush();

        return $reg;
    }

    private function createRegistrationWithoutYaml(
        string $userId,
        string $eventId,
        string $gameId,
        string $slotId,
    ): Registration {
        $now = new \DateTimeImmutable('2026-05-05T10:00:00+00:00');
        $reg = $this->createRegistration($eventId, $userId);
        $reg->replaceSlots([['slotId' => $slotId, 'gameId' => $gameId]], $now);
        $this->entityManager->flush();

        return $reg;
    }

    private function persistSession(string $eventId): Session
    {
        $session = Session::create(bin2hex(random_bytes(8)), $eventId, new \DateTimeImmutable());
        $this->entityManager->persist($session);
        $this->entityManager->flush();

        return $session;
    }

    private function persistSessionSlot(
        string $sessionId,
        string $registrationId,
        string $gameId,
        string $slotName,
        int $slotOrder,
        ?string $slotId = null,
    ): SessionSlot {
        $slot = SessionSlot::create(
            bin2hex(random_bytes(8)),
            $sessionId,
            $registrationId,
            $gameId,
            $slotName,
            $slotOrder,
            $slotId,
        );
        $this->entityManager->persist($slot);
        $this->entityManager->flush();

        return $slot;
    }

    private function patchStatus(string $sessionId, string $status): void
    {
        $body = ['status' => $status];
        if ('running' === $status) {
            $body['host'] = '10.0.0.1';
            $body['port'] = 9042;
            $body['password'] = 'secret';
        }
        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/sessions/%s/status', $sessionId), $body);
    }
}
