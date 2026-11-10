<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Domain\Entity\User;
use App\PersonalRuns\Domain\Entity\Run;
use App\Sessions\Domain\Entity\PasseurOpening;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Entity\SessionFeedEvent;

/** Story 52.5: what the Passeur opened - the notice of the opened world's player, the journals, the mark in the feed. */
final class PasseurJournalTest extends FunctionalTestCase
{
    private User $admin;
    private User $alice;
    private User $bob;
    private string $runId;
    private string $sessionId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Jean');
        $this->alice = $this->createUser('alice@example.org', ['ROLE_USER'], 'Alice');
        $this->bob = $this->createUser('bob@example.org', ['ROLE_USER'], 'Bob');

        $run = Run::create($this->alice->getId(), 'Run de test', new \DateTimeImmutable('-1 day'));
        $this->entityManager->persist($run);
        $this->runId = $run->getId();
        $this->sessionId = bin2hex(random_bytes(16));
        $this->entityManager->persist(Session::createRunning($this->sessionId, $this->runId, 'localhost', 38281, null, null, new \DateTimeImmutable('-3 hours')));
        $run->attachSession($this->sessionId);
        // Alice paid twice to open chests of Bob's world; the second time the bridge failed.
        $this->opening('o1', 401, 'Link House', 'Claw', PasseurOpening::STATUS_OPENED, 30, '-2 hours');
        $this->opening('o2', 402, 'Pyramid', 'Dash', PasseurOpening::STATUS_FAILED, 0, '-1 hour');
        $this->entityManager->flush();
    }

    public function testThePlayerOfTheOpenedWorldSeesWhatWasOpenedUntilTheyCloseIt(): void
    {
        $this->loginAs($this->bob);
        $this->client->request('GET', $this->noticeUrl());

        self::assertResponseStatusCodeSame(200);
        $data = $this->data();
        self::assertSame(30, $data['total']);
        self::assertSame('bronze', $data['kind']);
        self::assertIsArray($data['openings']);
        self::assertSame(['Claw'], array_column($data['openings'], 'itemName'));
        self::assertSame(['Alice'], array_column($data['openings'], 'buyerName'));

        $this->client->request('DELETE', $this->noticeUrl());
        self::assertResponseStatusCodeSame(204);
        $this->client->request('GET', $this->noticeUrl());
        self::assertSame(['openings' => [], 'total' => 0, 'kind' => null], $this->data());

        // A new opening brings it back, with only what is new.
        $this->opening('o3', 403, 'Dam', 'Geo', PasseurOpening::STATUS_OPENED, 25, '-5 minutes');
        $this->entityManager->flush();
        $this->client->request('GET', $this->noticeUrl());
        $data = $this->data();
        self::assertSame(25, $data['total']);
        self::assertIsArray($data['openings']);
        self::assertSame(['Geo'], array_column($data['openings'], 'itemName'));
    }

    public function testAnotherPlayerNeverSeesThatNotice(): void
    {
        $this->loginAs($this->alice);
        $this->client->request('GET', $this->noticeUrl());
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['openings' => [], 'total' => 0, 'kind' => null], $this->data());

        // Closing one's own (empty) notice leaves Bob's as it was.
        $this->client->request('DELETE', $this->noticeUrl());
        $this->loginAs($this->bob);
        $this->client->request('GET', $this->noticeUrl());
        self::assertSame(30, $this->data()['total']);
    }

    public function testTheRunOwnerAndTheStaffReadTheJournalOthersDoNot(): void
    {
        $runUrl = '/api/v1/runs/'.$this->runId.'/passeur-openings';
        $sessionUrl = '/api/v1/admin/sessions/'.$this->sessionId.'/passeur-openings';

        $this->loginAs($this->bob);
        $this->client->request('GET', $runUrl);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', $sessionUrl);
        self::assertResponseStatusCodeSame(403);

        $this->loginAs($this->alice);
        $this->client->request('GET', $runUrl);
        self::assertResponseStatusCodeSame(200);
        $rows = $this->decodedJsonResponse()['data'];
        self::assertIsArray($rows);
        self::assertSame(['Dash', 'Claw'], array_column($rows, 'itemName'), 'newest first, failures included');
        self::assertSame(['failed', 'opened'], array_column($rows, 'status'));

        $this->loginAs($this->admin);
        $this->client->request('GET', $sessionUrl);
        self::assertResponseStatusCodeSame(200);
        self::assertIsArray($this->decodedJsonResponse()['data']);
        self::assertCount(2, $this->decodedJsonResponse()['data']);
        $this->client->request('GET', '/api/v1/admin/sessions/nope/passeur-openings');
        self::assertResponseStatusCodeSame(404);
    }

    public function testTheEventJournalIsForTheStaff(): void
    {
        $now = new \DateTimeImmutable();
        $eventId = $this->createEvent('ArchiLAN 4', $now->modify('-1 day'), $now->modify('+1 day'))->getId();
        $this->entityManager->persist(new PasseurOpening(
            'e1', 'event-session', 'passeur:x:e1', $this->alice->getId(), 1, 2, $this->bob->getId(), 401, 'Link House', 'Claw',
            'event', $eventId, null, 60, PasseurOpening::STATUS_OPENED, $now, 30, buyerName: 'Alice', absentName: 'Bob',
        ));
        $this->entityManager->flush();
        $url = '/api/v1/admin/events/'.$eventId.'/passeur-openings';

        $this->loginAs($this->alice);
        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(403);

        $this->loginAs($this->admin);
        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(200);
        $rows = $this->decodedJsonResponse()['data'];
        self::assertIsArray($rows);
        self::assertSame(['e1'], array_column($rows, 'id'));
        self::assertSame([60], array_column($rows, 'price'));
    }

    public function testTheFeedMarksTheItemsThePasseurSent(): void
    {
        $this->entityManager->persist($this->find(401, 'Claw'));
        $this->entityManager->persist($this->find(402, 'Dash'));
        $this->entityManager->persist($this->find(500, 'Bow'));
        $this->entityManager->flush();

        $this->loginAs($this->alice);
        $this->client->request('GET', '/api/v1/parties/'.$this->sessionId.'/feed');

        self::assertResponseStatusCodeSame(200);
        $rows = $this->decodedJsonResponse()['data'];
        self::assertIsArray($rows);
        self::assertSame([true, false, false], array_column($rows, 'passeur'), 'only the chest the Passeur did open');
    }

    private function opening(string $id, int $location, string $locationName, string $item, string $status, int $share, string $at): void
    {
        $this->entityManager->persist(new PasseurOpening(
            $id, $this->sessionId, 'passeur:'.$id, $this->alice->getId(), 1, 2, $this->bob->getId(), $location, $locationName, $item,
            'bronze', null, $this->runId, 60, $status, new \DateTimeImmutable($at), $share,
            buyerName: 'Alice', absentName: 'Bob',
        ));
    }

    private function find(int $location, string $item): SessionFeedEvent
    {
        return new SessionFeedEvent(
            bin2hex(random_bytes(16)), $this->sessionId, SessionFeedEvent::TYPE_ITEM_RECEIVED, 'Bob sent '.$item,
            new \DateTimeImmutable('2026-11-09T10:00:00+00:00')->modify('+'.$location.' seconds'), 1, $item, 1, $location, 'Chest',
            2, 'Bob', 'Game', 1, 'Alice', 'Game',
        );
    }

    private function noticeUrl(): string
    {
        return '/api/v1/sessions/'.$this->sessionId.'/passeur/notice';
    }

    /**
     * @return array<array-key, mixed>
     */
    private function data(): array
    {
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);

        return $data;
    }
}
