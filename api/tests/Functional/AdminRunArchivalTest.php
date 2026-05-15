<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Events\Domain\Event;
use App\GameSelection\Domain\ArchipelagoGame;
use App\Identity\Domain\User;
use App\Registrations\Domain\Registration;
use App\Sessions\Application\Message\ArchiveRunJob;
use App\Sessions\Domain\RunAuditLog;
use App\Sessions\Domain\Session;
use App\Sessions\Domain\SessionSlot;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class AdminRunArchivalTest extends FunctionalTestCase
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
            $this->entityManager->getClassMetadata(RunAuditLog::class),
            $this->entityManager->getClassMetadata(Event::class),
        ];
        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    public function testForceEndDispatchesArchiveRunJob(): void
    {
        $session = $this->createRunningSession('run-arc-1', 'evt-arc-01');
        $admin = $this->createAdmin();
        $this->loginAs($admin);

        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/sessions/%s/force-end', $session->getId()));
        self::assertResponseStatusCodeSame(200);

        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.run_server');
        $enveloped = iterator_to_array($transport->get());

        $archiveJobs = array_filter(
            array_map(static fn ($e) => $e->getMessage(), $enveloped),
            static fn ($m) => $m instanceof ArchiveRunJob,
        );
        self::assertCount(1, $archiveJobs);

        /** @var ArchiveRunJob $archiveJob */
        $archiveJob = array_values($archiveJobs)[0];
        self::assertSame($session->getId(), $archiveJob->sessionId);
    }

    public function testArchiveCallbackStoresStatsOnSlots(): void
    {
        $user = $this->createUser('player1@example.org', ['ROLE_USER'], 'player1@example.org');
        $reg = $this->createRegistration('evt-arc-02', $user->getId());
        $session = $this->createFinishedSession('run-arc-2', 'evt-arc-02');

        $slot = SessionSlot::create(
            bin2hex(random_bytes(16)),
            $session->getId(),
            $reg->getId(),
            'game-arc-1',
            'Alice',
            1,
        );
        $this->entityManager->persist($slot);
        $this->entityManager->flush();

        $goalTime = '2026-05-06T12:00:00+00:00';

        $this->client->jsonRequest(
            'POST',
            sprintf('/api/v1/internal/sessions/%s/runner-callback', $session->getId()),
            [
                'status' => 'archived',
                'archived_save_path' => '/var/archives/run-arc-2.apsave',
                'archived_spoiler_path' => '/var/archives/run-arc-2.archipelago',
                'slots' => [
                    [
                        'slot_name' => 'Alice',
                        'checks_done' => 42,
                        'items_received' => 30,
                        'goal_reached_at' => $goalTime,
                    ],
                ],
            ],
            ['HTTP_X-Internal-Secret' => 'test-runner-secret'],
        );
        self::assertResponseStatusCodeSame(200);

        $response = $this->decodedJsonResponse();
        $responseData = $response['data'];
        self::assertIsArray($responseData);
        self::assertSame(true, $responseData['ok']);

        $this->entityManager->clear();
        $refreshedSlot = $this->entityManager->getRepository(SessionSlot::class)
            ->findOneBy(['sessionId' => $session->getId(), 'slotName' => 'Alice']);

        self::assertInstanceOf(SessionSlot::class, $refreshedSlot);
        self::assertSame(42, $refreshedSlot->getChecksDone());
        self::assertSame(30, $refreshedSlot->getItemsReceived());
        self::assertInstanceOf(\DateTimeImmutable::class, $refreshedSlot->getGoalReachedAt());

        $refreshedSession = $this->entityManager->find(Session::class, $session->getId());
        self::assertInstanceOf(Session::class, $refreshedSession);
        self::assertSame('/var/archives/run-arc-2.apsave', $refreshedSession->getArchivedSavePath());
        self::assertSame('/var/archives/run-arc-2.archipelago', $refreshedSession->getArchivedSpoilerPath());
    }

    public function testPublicResultsRequiresNoAuth(): void
    {
        $event = $this->makeEvent();
        $session = $this->createFinishedSession('run-arc-3', $event->getId());
        $user = $this->createUser('player2@example.org', ['ROLE_USER'], 'Player2');
        $reg = $this->createRegistration($event->getId(), $user->getId());

        $slot = SessionSlot::create(
            bin2hex(random_bytes(16)),
            $session->getId(),
            $reg->getId(),
            'game-arc-2',
            'Bob',
            1,
        );
        $slot->setChecksDone(10);
        $slot->setItemsReceived(5);
        $this->entityManager->persist($slot);
        $this->entityManager->flush();

        $this->client->jsonRequest('GET', sprintf('/api/v1/events/%s/session/results', $event->getId()));
        self::assertResponseStatusCodeSame(200);

        $response = $this->decodedJsonResponse();
        $responseData = $response['data'];
        self::assertIsArray($responseData);
        $sessionData = $responseData['session'];
        self::assertIsArray($sessionData);
        self::assertSame($session->getId(), $sessionData['id']);
        self::assertSame('finished', $sessionData['status']);
        $slotsData = $responseData['slots'];
        self::assertIsArray($slotsData);
        self::assertCount(1, $slotsData);
        $firstSlot = $slotsData[0];
        self::assertIsArray($firstSlot);
        self::assertSame('Bob', $firstSlot['slot_name']);
        self::assertSame(10, $firstSlot['checks_done']);
    }

    public function testExportJsonFormat(): void
    {
        $admin = $this->createAdmin();
        $this->loginAs($admin);

        $user = $this->createUser('player3@example.org', ['ROLE_USER'], 'PlayerThree');
        $reg = $this->createRegistration('evt-arc-04', $user->getId());
        $session = $this->createFinishedSession('run-arc-4', 'evt-arc-04');

        $slot = SessionSlot::create(
            bin2hex(random_bytes(16)),
            $session->getId(),
            $reg->getId(),
            'game-arc-3',
            'Charlie',
            1,
        );
        $slot->setChecksDone(55);
        $slot->setItemsReceived(40);
        $this->entityManager->persist($slot);
        $this->entityManager->flush();

        $this->client->jsonRequest('GET', sprintf('/api/v1/admin/sessions/%s/export', $session->getId()));
        self::assertResponseStatusCodeSame(200);

        $response = $this->decodedJsonResponse();
        $responseData = $response['data'];
        self::assertIsArray($responseData);
        self::assertCount(1, $responseData);
        $row = $responseData[0];
        self::assertIsArray($row);
        self::assertSame('Charlie', $row['slot_name']);
        self::assertSame(55, $row['checks_done']);
        self::assertSame(40, $row['items_received']);
        self::assertNull($row['goal_reached_at']);
    }

    public function testExportCsvFormat(): void
    {
        $admin = $this->createAdmin();
        $this->loginAs($admin);

        $user = $this->createUser('player4@example.org', ['ROLE_USER'], 'PlayerFour');
        $reg = $this->createRegistration('evt-arc-05', $user->getId());
        $session = $this->createFinishedSession('run-arc-5', 'evt-arc-05');

        $slot = SessionSlot::create(
            bin2hex(random_bytes(16)),
            $session->getId(),
            $reg->getId(),
            'game-arc-4',
            'Diana',
            1,
        );
        $slot->setChecksDone(22);
        $slot->setItemsReceived(15);
        $this->entityManager->persist($slot);
        $this->entityManager->flush();

        $this->client->request('GET', sprintf('/api/v1/admin/sessions/%s/export?format=csv', $session->getId()));
        self::assertResponseStatusCodeSame(200);

        $content = $this->client->getResponse()->getContent() ?: '';
        self::assertStringContainsString('slot_name,player,game,checks_done,items_received,goal_reached_at', $content);
        self::assertStringContainsString('Diana', $content);
        self::assertStringContainsString(',22,', $content);
        self::assertStringContainsString(',15,', $content);

        $contentType = $this->client->getResponse()->headers->get('Content-Type') ?? '';
        self::assertStringStartsWith('text/csv', $contentType);
    }

    // ─── helpers ────────────────────────────────────────────────────────────────

    private function makeEvent(): Event
    {
        $now = new \DateTimeImmutable('2026-05-01T10:00:00+00:00');
        $event = $this->createEvent(
            'Test Event',
            $now,
            $now->modify('+2 days'),
            capacity: 30,
            registrationOpensAt: $now->modify('-30 days'),
            registrationClosesAt: $now->modify('-1 day'),
        );
        $this->transitionEventTo($event, Event::STATUS_COMPLETED, $now);
        $this->entityManager->flush();

        return $event;
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

    private function createFinishedSession(string $id, string $eventId): Session
    {
        $now = new \DateTimeImmutable('2026-05-06T11:00:00+00:00');
        $session = Session::create($id, $eventId, $now);
        $session->transition(Session::STATUS_VALIDATING, $now);
        $session->transition(Session::STATUS_READY, $now);
        $session->transition(Session::STATUS_GENERATING, $now);
        $session->transition(Session::STATUS_GENERATED, $now);
        $session->transition(Session::STATUS_LAUNCHING, $now);
        $session->transition(Session::STATUS_RUNNING, $now, 'bridge.local', 38281, 'secret', 5000);
        $session->transition(Session::STATUS_FINISHED, $now->modify('+2 hours'));
        $this->entityManager->persist($session);
        $this->entityManager->flush();

        return $session;
    }

    private function createAdmin(): User
    {
        return $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
    }
}
