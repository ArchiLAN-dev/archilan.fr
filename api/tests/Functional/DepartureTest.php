<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Community\Domain\Entity\Notification;
use App\Identity\Domain\Entity\User;
use App\PersonalRuns\Domain\Entity\Run;
use App\Registrations\Domain\Entity\RegistrationPresenceWindow;
use App\Sessions\Application\Command\RequestDeclaredDepartures;
use App\Sessions\Domain\Entity\DepartureRequest;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Entity\SessionPlayersSnapshot;
use App\Sessions\Domain\Entity\SessionSlot;
use App\Sessions\Domain\Entity\SlotPlaySpan;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** Story 52.6: a player leaves - they say so, or their declared departure passes - and a decider releases or not. */
final class DepartureTest extends FunctionalTestCase
{
    private User $admin;
    private User $alice;
    private User $bob;
    private string $sessionId;
    private string $eventId = '';
    private string $bobRegistration = '';
    private MockHttpClient $httpClient;
    /** @var list<string> */
    private array $commands = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->client->disableReboot();
        $this->admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Jean');
        $this->alice = $this->createUser('alice@example.org', ['ROLE_USER'], 'Alice');
        $this->bob = $this->createUser('bob@example.org', ['ROLE_USER'], 'Bob Le Grand');

        $httpClient = self::getContainer()->get(MockHttpClient::class);
        self::assertInstanceOf(MockHttpClient::class, $httpClient);
        $this->httpClient = $httpClient;
        $this->bridgeAnswers(200);
    }

    public function testThePlayerSaysTheyLeaveOnceAndTheStaffIsTold(): void
    {
        $this->eventSession();
        $this->loginAs($this->bob);

        $this->client->request('GET', $this->slotUrl());
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['available' => true, 'requestedAt' => null], $this->decodedJsonResponse()['data']);

        $this->client->request('POST', $this->slotUrl());
        self::assertResponseStatusCodeSame(204);
        $this->client->request('POST', $this->slotUrl());
        self::assertResponseStatusCodeSame(409);
        self::assertSame('departure_already_requested', $this->errorCode());

        $this->client->request('GET', $this->slotUrl());
        $data = $this->decodedJsonResponse()['data'];
        self::assertIsArray($data);
        self::assertIsString($data['requestedAt']);

        $notifications = $this->notificationsOf($this->admin);
        self::assertCount(1, $notifications);
        self::assertSame('Bob Le Grand', $notifications[0]->getPayload()['playerName'] ?? null);
        self::assertSame([], $this->notificationsOf($this->alice), 'only the staff of an event');
        self::assertSame([], $this->commands, 'saying one leaves releases nothing');
    }

    public function testOnlyThePlayerOfTheSlotSaysTheyLeave(): void
    {
        $this->eventSession();
        $this->loginAs($this->alice);

        $this->client->request('POST', $this->slotUrl());
        self::assertResponseStatusCodeSame(403);
    }

    public function testTheStaffReleasesTheWorldThroughTheBridge(): void
    {
        $this->eventSession();
        $id = $this->bobLeaves();

        $this->loginAs($this->admin);
        $this->client->request('GET', '/api/v1/sessions/'.$this->sessionId.'/departures');
        self::assertResponseStatusCodeSame(200);
        $rows = $this->decodedJsonResponse()['data'];
        self::assertIsArray($rows);
        self::assertSame([['id' => $id, 'slotIndex' => 2, 'slotName' => 'Bob Le Grand', 'origin' => 'player']], array_map(static fn (mixed $row): array => is_array($row) ? array_diff_key($row, ['createdAt' => true]) : [], $rows));

        $this->client->request('POST', $this->decisionUrl($id, 'release'));
        self::assertResponseStatusCodeSame(204);
        self::assertSame(['!admin /release Bob Le Grand'], $this->commands);
        self::assertSame(DepartureRequest::STATUS_RELEASED, $this->request($id)->getStatus());

        $this->client->request('GET', '/api/v1/sessions/'.$this->sessionId.'/departures');
        self::assertSame([], $this->decodedJsonResponse()['data']);
        $this->client->request('POST', $this->decisionUrl($id, 'release'));
        self::assertResponseStatusCodeSame(409);
    }

    public function testABridgeThatFailsLeavesTheDepartureOpen(): void
    {
        $this->eventSession();
        $id = $this->bobLeaves();
        $this->bridgeAnswers(500);

        $this->loginAs($this->admin);
        $this->client->request('POST', $this->decisionUrl($id, 'release'));

        self::assertResponseStatusCodeSame(503);
        self::assertSame('bridge_unavailable', $this->errorCode());
        self::assertSame(DepartureRequest::STATUS_OPEN, $this->request($id)->getStatus());
    }

    public function testADismissedDepartureClosesWithoutARelease(): void
    {
        $this->eventSession();
        $id = $this->bobLeaves();

        $this->loginAs($this->admin);
        $this->client->request('POST', $this->decisionUrl($id, 'dismiss'));

        self::assertResponseStatusCodeSame(204);
        self::assertSame(DepartureRequest::STATUS_DISMISSED, $this->request($id)->getStatus());
        self::assertSame([], $this->commands);
    }

    public function testAPlayerNeitherSeesNorHandlesTheDepartures(): void
    {
        $this->eventSession();
        $id = $this->bobLeaves();

        $this->loginAs($this->bob);
        $this->client->request('GET', '/api/v1/sessions/'.$this->sessionId.'/departures');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', $this->decisionUrl($id, 'release'));
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', $this->decisionUrl($id, 'dismiss'));
        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->commands);
    }

    public function testThePrivateRunOwnerHandlesTheDeparturesOfTheirRun(): void
    {
        $run = Run::create($this->alice->getId(), 'Run de test', new \DateTimeImmutable('-1 day'));
        $this->entityManager->persist($run);
        $this->session($run->getId(), $this->alice->getId(), $this->bob->getId());
        $run->attachSession($this->sessionId);
        $this->entityManager->flush();
        $id = $this->bobLeaves();

        self::assertCount(1, $this->notificationsOf($this->alice), 'the owner is told');
        self::assertSame([], $this->notificationsOf($this->admin));

        $this->loginAs($this->alice);
        $this->client->request('POST', $this->decisionUrl($id, 'release'));
        self::assertResponseStatusCodeSame(204);
        self::assertSame(['!admin /release Bob Le Grand'], $this->commands);
    }

    public function testADeclaredDepartureThatPassedBecomesADepartureOnce(): void
    {
        $this->eventSession();
        $now = new \DateTimeImmutable();
        // Bob's last window ended an hour ago: his declared departure passed.
        $this->entityManager->persist(RegistrationPresenceWindow::create($this->bobRegistration, $now->modify('-1 day'), $now->modify('-1 hour')));
        $this->entityManager->flush();

        self::assertSame(1, $this->pass());
        self::assertSame(0, $this->pass(), 'no duplicate');
        $request = $this->entityManager->getRepository(DepartureRequest::class)->findOneBy(['sessionId' => $this->sessionId]);
        self::assertInstanceOf(DepartureRequest::class, $request);
        self::assertSame(DepartureRequest::ORIGIN_SCHEDULE, $request->getOrigin());

        // Dismissed (« il revient »): the same departure never comes back.
        $request->dismiss($this->admin->getId(), $now);
        $this->entityManager->flush();
        self::assertSame(0, $this->pass());
    }

    public function testADeclaredDepartureWaitsWhileThePlayerIsStillConnected(): void
    {
        $this->eventSession();
        $now = new \DateTimeImmutable();
        $this->entityManager->persist(RegistrationPresenceWindow::create($this->bobRegistration, $now->modify('-1 day'), $now->modify('-1 hour')));
        $this->entityManager->persist(SlotPlaySpan::open(bin2hex(random_bytes(16)), $this->sessionId, 'Bob Le Grand', $now->modify('-30 seconds')));
        // Alice declared nothing: she is there the whole event.
        $this->entityManager->flush();

        self::assertSame(0, $this->pass());
    }

    private function eventSession(): void
    {
        $now = new \DateTimeImmutable();
        $this->eventId = $this->createEvent('ArchiLAN 4', $now->modify('-1 day'), $now->modify('+1 day'))->getId();
        $alice = $this->createRegistration($this->eventId, $this->alice->getId())->getId();
        $this->bobRegistration = $this->createRegistration($this->eventId, $this->bob->getId())->getId();
        $this->session($this->eventId, $alice, $this->bobRegistration);
    }

    private function session(string $ownerId, string $aliceSlotOwner, string $bobSlotOwner): void
    {
        $this->sessionId = bin2hex(random_bytes(16));
        $started = new \DateTimeImmutable('-3 hours');
        $this->entityManager->persist(Session::createRunning($this->sessionId, $ownerId, 'localhost', 38281, null, 5000, $started));
        $this->entityManager->persist(SessionSlot::create(bin2hex(random_bytes(16)), $this->sessionId, $aliceSlotOwner, 'game-1', 'Alice', 1, 'slot-alice'));
        $this->entityManager->persist(SessionSlot::create(bin2hex(random_bytes(16)), $this->sessionId, $bobSlotOwner, 'game-1', 'Bob Le Grand', 2, 'slot-bob'));
        $this->entityManager->persist(new SessionPlayersSnapshot($this->sessionId, ['slots' => ['1' => ['slot_name' => 'Alice'], '2' => ['slot_name' => 'Bob Le Grand']]], $started));
        $this->entityManager->flush();
    }

    private function bobLeaves(): string
    {
        $this->loginAs($this->bob);
        $this->client->request('POST', $this->slotUrl());
        self::assertResponseStatusCodeSame(204);
        $request = $this->entityManager->getRepository(DepartureRequest::class)->findOneBy(['sessionId' => $this->sessionId]);
        self::assertInstanceOf(DepartureRequest::class, $request);

        return $request->getId();
    }

    private function pass(): int
    {
        $pass = self::getContainer()->get(RequestDeclaredDepartures::class);
        self::assertInstanceOf(RequestDeclaredDepartures::class, $pass);

        return $pass->execute();
    }

    private function bridgeAnswers(int $status): void
    {
        $this->httpClient->setResponseFactory(function (string $method, string $url, array $options) use ($status): MockResponse {
            $body = $options['body'] ?? null;
            $decoded = is_string($body) ? json_decode($body, true) : null;
            if (200 === $status && is_array($decoded) && is_string($decoded['command'] ?? null)) {
                $this->commands[] = $decoded['command'];
            }

            return new MockResponse('{"ok":true}', ['http_code' => $status]);
        });
    }

    private function request(string $id): DepartureRequest
    {
        $this->entityManager->clear();
        $request = $this->entityManager->find(DepartureRequest::class, $id);
        self::assertInstanceOf(DepartureRequest::class, $request);

        return $request;
    }

    /**
     * @return list<Notification>
     */
    private function notificationsOf(User $user): array
    {
        return $this->entityManager->getRepository(Notification::class)->findBy(['recipientId' => $user->getId(), 'type' => 'departure_requested']);
    }

    private function slotUrl(): string
    {
        return sprintf('/api/v1/sessions/%s/slots/2/departure', $this->sessionId);
    }

    private function decisionUrl(string $id, string $decision): string
    {
        return sprintf('/api/v1/sessions/%s/departures/%s/%s', $this->sessionId, $id, $decision);
    }

    private function errorCode(): mixed
    {
        $error = $this->decodedJsonResponse()['error'] ?? null;

        return is_array($error) ? ($error['code'] ?? null) : null;
    }
}
