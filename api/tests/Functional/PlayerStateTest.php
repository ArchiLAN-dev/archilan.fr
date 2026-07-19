<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Domain\Entity\User;
use App\Registrations\Domain\Entity\Registration;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Entity\SessionSlot;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class PlayerStateTest extends FunctionalTestCase
{
    private MockHttpClient $httpClient;

    protected function setUp(): void
    {
        parent::setUp();

        $httpClient = self::getContainer()->get(MockHttpClient::class);
        self::assertInstanceOf(MockHttpClient::class, $httpClient);
        $this->httpClient = $httpClient;
    }

    public function testPlayersProxyReturnsState(): void
    {
        $session = $this->createRunningSession('run-proxy-1', 'evt-001');
        $player = $this->createPlayer('alice@example.org', 'Alice');
        $this->makeRegistration($player->getId(), 'evt-001', confirmed: true);
        $this->loginAs($player);

        $bridgeState = '{"slots":{"1":{"slot_name":"Alice_HK1","checks_done":5,"checks_total":47,"items_received":3,"client_status":20,"goal_reached_at":null}}}';
        $this->httpClient->setResponseFactory(new MockResponse($bridgeState, ['http_code' => 200]));

        $this->client->jsonRequest('GET', sprintf('/api/v1/sessions/%s/players', $session->getId()));
        self::assertResponseStatusCodeSame(200);

        $response = $this->decodedJsonResponse();
        $responseData = $response['data'];
        self::assertIsArray($responseData);
        $slots = $responseData['slots'];
        self::assertIsArray($slots);
        self::assertArrayHasKey('1', $slots);
        $slot1 = $slots['1'];
        self::assertIsArray($slot1);
        self::assertSame('Alice_HK1', $slot1['slot_name']);
        self::assertSame(5, $slot1['checks_done']);
        self::assertSame(20, $slot1['client_status']);
    }

    public function testPlayersReturns503WhenBridgeUnreachable(): void
    {
        $session = $this->createRunningSession('run-503-1', 'evt-001');
        $admin = $this->createAdmin();
        $this->loginAs($admin);

        $this->httpClient->setResponseFactory(static function (): never {
            throw new \Symfony\Component\HttpClient\Exception\TransportException('Connection refused');
        });

        $this->client->jsonRequest('GET', sprintf('/api/v1/sessions/%s/players', $session->getId()));
        self::assertResponseStatusCodeSame(503);

        $response = $this->decodedJsonResponse();
        $errorData = $response['error'];
        self::assertIsArray($errorData);
        self::assertSame('bridge_unavailable', $errorData['code']);
    }

    public function testPlayersReturns403ForNonRegistrant(): void
    {
        $session = $this->createRunningSession('run-403-1', 'evt-001');
        $player = $this->createPlayer('bob@example.org', 'Bob');
        $this->loginAs($player);

        $this->client->jsonRequest('GET', sprintf('/api/v1/sessions/%s/players', $session->getId()));
        self::assertResponseStatusCodeSame(403);
    }

    public function testPlayersTokenAllowsRegistrant(): void
    {
        $session = $this->createSession('run-tok-1', 'evt-001');
        $player = $this->createPlayer('charlie@example.org', 'Charlie');
        $this->makeRegistration($player->getId(), 'evt-001', confirmed: true);
        $this->loginAs($player);

        $this->client->jsonRequest('GET', sprintf('/api/v1/sessions/%s/players-token', $session->getId()));
        self::assertResponseStatusCodeSame(200);

        $data = $this->decodedJsonResponse()['data'];
        self::assertIsArray($data);
        self::assertIsString($data['token']);
        self::assertSame('runs/run-tok-1/players', $data['topic']);
    }

    public function testPlayersTokenForbidsNonRegistrant(): void
    {
        $session = $this->createSession('run-tok-2', 'evt-001');
        $player = $this->createPlayer('dave@example.org', 'Dave');
        $this->loginAs($player);

        $this->client->jsonRequest('GET', sprintf('/api/v1/sessions/%s/players-token', $session->getId()));
        self::assertResponseStatusCodeSame(403);
    }

    public function testPlayersTokenAllowsAdmin(): void
    {
        $session = $this->createSession('run-tok-3', 'evt-001');
        $admin = $this->createAdmin();
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', sprintf('/api/v1/sessions/%s/players-token', $session->getId()));
        self::assertResponseStatusCodeSame(200);

        $data = $this->decodedJsonResponse()['data'];
        self::assertIsArray($data);
        self::assertSame('runs/run-tok-3/players', $data['topic']);
    }

    public function testUpdateHintStatusRejectsNonSettableStatus(): void
    {
        // "found" (40) is bridge-managed and must not be settable by the player.
        $session = $this->createRunningSession('run-hint-2', 'evt-001');
        $admin = $this->createAdmin();
        $this->loginAs($admin);

        $this->client->jsonRequest('PATCH', sprintf('/api/v1/sessions/%s/slots/1/hints/123', $session->getId()), ['status' => 40]);
        self::assertResponseStatusCodeSame(422);
        $error = $this->decodedJsonResponse()['error'] ?? null;
        self::assertIsArray($error);
        self::assertSame('validation_error', $error['code']);
    }

    public function testUpdateHintStatusForbidsNonRegistrant(): void
    {
        $session = $this->createRunningSession('run-hint-3', 'evt-001');
        $player = $this->createPlayer('eve@example.org', 'Eve');
        $this->loginAs($player);

        $this->client->jsonRequest('PATCH', sprintf('/api/v1/sessions/%s/slots/1/hints/123', $session->getId()), ['status' => 30]);
        self::assertResponseStatusCodeSame(403);
    }

    // ─── slot-ownership gate (issues #252 / #253) ───────────────────────────────

    public function testUpdateHintStatusForbidsForeignSlot(): void
    {
        // Alice owns slot 0; she must not be able to change the hint priority of Bob's slot 1 (#253).
        $session = $this->createRunningSession('run-own-hint', 'evt-001');
        [$alice] = $this->twoRegistrantsWithSlots($session, 'evt-001', 'aliceh');
        $this->loginAs($alice);

        $this->client->jsonRequest('PATCH', sprintf('/api/v1/sessions/%s/slots/1/hints/123', $session->getId()), ['status' => 30]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testUpdateHintStatusAllowsOwnSlot(): void
    {
        // Slot owner reaches validation (status 40 is bridge-managed -> 422), proving the ownership gate passed.
        $session = $this->createRunningSession('run-own-hint-ok', 'evt-001');
        [$alice] = $this->twoRegistrantsWithSlots($session, 'evt-001', 'aliceok');
        $this->loginAs($alice);

        $this->client->jsonRequest('PATCH', sprintf('/api/v1/sessions/%s/slots/0/hints/123', $session->getId()), ['status' => 40]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testSlotItemLocationsForbidsForeignSlot(): void
    {
        // Item locations of another slot are spoilers -> a co-registrant must not read them (#252).
        $session = $this->createRunningSession('run-own-il', 'evt-001');
        [$alice] = $this->twoRegistrantsWithSlots($session, 'evt-001', 'ilalice');
        $this->loginAs($alice);

        $this->client->jsonRequest('GET', sprintf('/api/v1/sessions/%s/slots/1/item-locations', $session->getId()));
        self::assertResponseStatusCodeSame(403);
    }

    public function testSlotItemLocationsForbidsRegistrantWithoutSlot(): void
    {
        // A confirmed registrant (session-authorized) who owns no slot is still denied - the previous
        // session-level check let this through, which was the #252 leak.
        $session = $this->createRunningSession('run-noslot-il', 'evt-001');
        $this->twoRegistrantsWithSlots($session, 'evt-001', 'ilnoslot');
        $carol = $this->createPlayer('carol-il@example.org', 'Carol');
        $this->makeRegistration($carol->getId(), 'evt-001', confirmed: true);
        $this->loginAs($carol);

        $this->client->jsonRequest('GET', sprintf('/api/v1/sessions/%s/slots/0/item-locations', $session->getId()));
        self::assertResponseStatusCodeSame(403);
    }

    public function testSlotItemLocationsAllowsOwnSlot(): void
    {
        // Owner passes the gate; on a not-yet-running session the request stops at the running-state
        // check (409), proving the ownership gate passed without needing a live bridge.
        $session = $this->createSession('run-own-il-ok', 'evt-001');
        [$alice] = $this->twoRegistrantsWithSlots($session, 'evt-001', 'ilok');
        $this->loginAs($alice);

        $this->client->jsonRequest('GET', sprintf('/api/v1/sessions/%s/slots/0/item-locations', $session->getId()));
        self::assertResponseStatusCodeSame(409);
    }

    public function testSlotItemLocationsAllowsAdminOnAnySlot(): void
    {
        $session = $this->createSession('run-admin-il', 'evt-001');
        $this->twoRegistrantsWithSlots($session, 'evt-001', 'iladmin');
        $admin = $this->createAdmin();
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', sprintf('/api/v1/sessions/%s/slots/1/item-locations', $session->getId()));
        self::assertResponseStatusCodeSame(409);
    }

    // ─── helpers ────────────────────────────────────────────────────────────────

    /**
     * Alice owns slot 0, Bob owns slot 1, both confirmed registrants of $eventId. Returns [alice, bob].
     *
     * @return array{0: User, 1: User}
     */
    private function twoRegistrantsWithSlots(Session $session, string $eventId, string $tag): array
    {
        $alice = $this->createPlayer($tag.'-alice@example.org', 'Alice');
        $bob = $this->createPlayer($tag.'-bob@example.org', 'Bob');
        $regA = $this->makeRegistration($alice->getId(), $eventId, confirmed: true);
        $regB = $this->makeRegistration($bob->getId(), $eventId, confirmed: true);
        $this->createSlot($session->getId(), $regA->getId(), 0);
        $this->createSlot($session->getId(), $regB->getId(), 1);

        return [$alice, $bob];
    }

    private function createSlot(string $sessionId, string $registrationId, int $slotOrder): void
    {
        $slot = SessionSlot::create(
            bin2hex(random_bytes(16)),
            $sessionId,
            $registrationId,
            'game-'.$slotOrder,
            'Slot'.$slotOrder,
            $slotOrder,
        );
        $this->entityManager->persist($slot);
        $this->entityManager->flush();
    }

    private function createSession(string $id, string $eventId): Session
    {
        $session = Session::create($id, $eventId, new \DateTimeImmutable());
        $this->entityManager->persist($session);
        $this->entityManager->flush();

        return $session;
    }

    private function createRunningSession(string $id, string $eventId): Session
    {
        $now = new \DateTimeImmutable();
        $session = Session::create($id, $eventId, $now);
        $session->transition(Session::STATUS_VALIDATING, $now);
        $session->transition(Session::STATUS_READY, $now);
        $session->transition(Session::STATUS_GENERATING, $now);
        $session->transition(Session::STATUS_GENERATED, $now);
        $session->transition(Session::STATUS_LAUNCHING, $now);
        $session->transition(Session::STATUS_RUNNING, $now, 'bridge.local', 38281, 'secret', 5000);
        $this->entityManager->persist($session);
        $this->entityManager->flush();

        return $session;
    }

    private function createAdmin(): User
    {
        return $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
    }

    private function createPlayer(string $email, string $displayName): User
    {
        return $this->createUser($email, ['ROLE_USER'], $displayName);
    }

    private function makeRegistration(string $userId, string $eventId, bool $confirmed): Registration
    {
        $now = new \DateTimeImmutable('2026-05-06T10:00:00+00:00');
        $registration = $this->createRegistration($eventId, $userId);
        if ($confirmed) {
            $registration->confirm($now);
            $this->entityManager->flush();
        }

        return $registration;
    }
}
