<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\GameSelection\Domain\ArchipelagoGame;
use App\Identity\Domain\User;
use App\Registrations\Domain\Registration;
use App\Sessions\Application\Message\GenerateRunJob;
use App\Sessions\Domain\Session;
use App\Sessions\Domain\SessionSlot;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

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
            $this->entityManager->getClassMetadata(ArchipelagoGame::class),
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

    public function testValidateTransitionsToValidatingAndDispatchesMessage(): void
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
        self::assertSame('validating', $data['status']);

        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.run_generation');
        self::assertCount(1, $transport->getSent());
        /** @var GenerateRunJob $message */
        $message = $transport->getSent()[0]->getMessage();
        self::assertInstanceOf(GenerateRunJob::class, $message);
        self::assertSame($session->getId(), $message->sessionId);
        self::assertSame('validate', $message->phase);
        self::assertCount(1, $message->slots);
        self::assertSame('Alice_HK', $message->slots[0]['slotName']);
        self::assertSame('Alice', $message->slots[0]['playerName']);
        self::assertSame('Hollow Knight', $message->slots[0]['archipelagoGameName']);
        self::assertSame("name: Alice\ngame: Hollow Knight\n", $message->slots[0]['playerYaml']);
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
        // Both players play the same game → collision → Alice_HK1, Bob_HK
        self::assertSame('Alice_HK', $slots[0]->getSlotName());
        self::assertSame('Bob_HK', $slots[1]->getSlotName());
    }

    public function testValidateReturns409WhenSessionNotDraft(): void
    {
        $admin = $this->createAdmin();
        $this->loginAs($admin);

        $session = $this->persistSession('evt-001');
        // Move to validating via PATCH
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

        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.run_generation');
        self::assertCount(0, $transport->getSent());
    }

    // ─── Callback - validation errors ─────────────────────────────────────────

    public function testCallbackWithErrorsTransitionsToDraftAndStoresErrors(): void
    {
        $admin = $this->createAdmin();
        $this->loginAs($admin);
        $session = $this->persistSession('evt-001');
        $this->patchStatus($session->getId(), 'validating');

        $this->sendCallback($session->getId(), [
            'status' => 'draft',
            'errors' => [
                ['slotName' => 'Alice_HK', 'errors' => ['Le nom de jeu Archipelago est manquant.']],
            ],
        ]);

        self::assertResponseIsSuccessful();
        $data = $this->decodedJsonResponse()['data'];
        self::assertIsArray($data);
        self::assertSame('draft', $data['status']);

        $this->entityManager->clear();
        $refreshed = $this->entityManager->find(Session::class, $session->getId());
        self::assertInstanceOf(Session::class, $refreshed);
        self::assertNotNull($refreshed->getValidationErrors());
        self::assertCount(1, $refreshed->getValidationErrors());
        self::assertSame('Alice_HK', $refreshed->getValidationErrors()[0]['slotName']);
    }

    public function testValidationErrorsClearedOnReValidate(): void
    {
        $admin = $this->createAdmin();
        $this->loginAs($admin);

        $player = $this->createUser('alice@example.org', ['ROLE_USER'], 'Alice');
        $game = $this->makeGame('Hollow Knight', 'Hollow Knight');
        $slotId = 'slot-hk-1';
        $reg = $this->createRegistrationWithYaml($player->getId(), 'evt-001', $game->getId(), $slotId, 'yaml: x');
        $session = $this->persistSession('evt-001');
        $this->persistSessionSlot($session->getId(), $reg->getId(), $game->getId(), 'placeholder', 0, $slotId);

        // Simulate a previous failed validation
        $this->patchStatus($session->getId(), 'validating');
        $this->sendCallback($session->getId(), [
            'status' => 'draft',
            'errors' => [['slotName' => 'Alice_HK', 'errors' => ['some error']]],
        ]);

        // Re-validate - re-login so admin cookie is set correctly after callback request
        $this->loginAs($admin);
        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/sessions/%s/validate', $session->getId()));
        self::assertResponseIsSuccessful();

        $this->entityManager->clear();
        $refreshed = $this->entityManager->find(Session::class, $session->getId());
        self::assertInstanceOf(Session::class, $refreshed);
        self::assertNull($refreshed->getValidationErrors());
    }

    // ─── Callback - ready ─────────────────────────────────────────────────────

    public function testCallbackWithReadyTransitionsToReady(): void
    {
        $admin = $this->createAdmin();
        $this->loginAs($admin);
        $session = $this->persistSession('evt-001');
        $this->patchStatus($session->getId(), 'validating');

        $this->sendCallback($session->getId(), ['status' => 'ready']);

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

    private function makeGame(string $name, ?string $archipelagoGameName): ArchipelagoGame
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

    /** @param array<string, mixed> $payload */
    private function sendCallback(string $sessionId, array $payload): void
    {
        $this->client->request(
            'POST',
            sprintf('/api/v1/internal/sessions/%s/runner-callback', $sessionId),
            [],
            [],
            ['HTTP_X_INTERNAL_SECRET' => 'test-runner-secret', 'CONTENT_TYPE' => 'application/json'],
            json_encode($payload, JSON_THROW_ON_ERROR),
        );
    }
}
