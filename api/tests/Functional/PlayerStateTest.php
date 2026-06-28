<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Domain\User;
use App\Registrations\Domain\Registration;
use App\Sessions\Domain\Session;
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

    // ─── helpers ────────────────────────────────────────────────────────────────

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
