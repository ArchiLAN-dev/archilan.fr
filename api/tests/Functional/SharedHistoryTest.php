<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Community\Domain\Entity\Block;
use App\Community\Domain\Entity\Friendship;
use App\Identity\Domain\Entity\User;
use App\PersonalRuns\Domain\Entity\Run;
use App\PersonalRuns\Domain\Entity\RunParticipant;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Entity\SessionFeedEvent;
use App\Sessions\Domain\Entity\SessionRecap;
use App\Sessions\Domain\Entity\SessionSlot;

/**
 * Story 43.9: « Vous avez joué ensemble » on another member's profile.
 */
final class SharedHistoryTest extends FunctionalTestCase
{
    private User $viewer;

    private User $other;

    private string $gameId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->viewer = $this->createUser('viewer@example.org', displayName: 'Viewer', slug: 'viewer');
        $this->other = $this->createUser('other@example.org', displayName: 'Other', slug: 'other');
        $this->gameId = $this->createGame('Hollow Knight', 'hollow-knight')->getId();
    }

    public function testTheSessionsPlayedTogetherAndTheItemsExchangedAreSummed(): void
    {
        $event = $this->createEvent('LAN', new \DateTimeImmutable('2026-03-01T10:00:00+00:00'), new \DateTimeImmutable('2026-03-02T18:00:00+00:00'), published: true, isPublic: true);
        $lan = $this->finished($event->getId(), '2026-03-02T18:00:00+00:00');
        $this->eventSlot($lan, $event->getId(), $this->viewer, 'ViewerHK');
        $this->eventSlot($lan, $event->getId(), $this->other, 'OtherHK');
        $this->entityManager->persist(new SessionRecap($lan->getId(), new \DateTimeImmutable(), [], [], [], []));
        foreach ([['ViewerHK', 'OtherHK'], ['ViewerHK', 'OtherHK'], ['OtherHK', 'ViewerHK']] as [$from, $to]) {
            $this->entityManager->persist($this->item($lan, $from, $to, '2026-03-02T12:00:00+00:00'));
        }

        $run = Run::create($this->other->getId(), 'Run secrète', new \DateTimeImmutable());
        $this->entityManager->persist($run);
        $this->entityManager->persist(RunParticipant::create($run->getId(), $this->viewer->getId(), new \DateTimeImmutable()));
        $runSession = $this->finished($run->getId(), '2026-05-01T18:00:00+00:00');
        $run->attachSession($runSession->getId());
        $this->runSlot($runSession, $this->viewer, 'ViewerCeleste');
        $this->runSlot($runSession, $this->other, 'OtherCeleste');

        $alone = $this->finished($run->getId(), '2026-06-01T18:00:00+00:00');
        $this->runSlot($alone, $this->other, 'OtherSolo');
        $this->entityManager->flush();

        $data = $this->history('other');
        self::assertIsArray($data);
        self::assertSame(2, $data['count']);
        self::assertFalse($data['isFriend']);
        self::assertSame('2026-03-02T18:00:00+00:00', $data['firstAt']);
        self::assertSame('2026-05-01T18:00:00+00:00', $data['lastAt']);
        self::assertIsArray($data['latest']);
        self::assertIsArray($data['latest'][0]);
        self::assertIsArray($data['latest'][1]);
        self::assertSame(['run', 'Run secrète', false], [$data['latest'][0]['kind'], $data['latest'][0]['title'], $data['latest'][0]['recap']]);
        self::assertSame(['event', 'LAN', true], [$data['latest'][1]['kind'], $data['latest'][1]['title'], $data['latest'][1]['recap']]);
        self::assertSame(['sent' => 2, 'received' => 1, 'since' => '2026-03-02T12:00:00+00:00'], $data['items']);
    }

    public function testNothingInCommonForAStrangerOneselfOrAcrossABlock(): void
    {
        $event = $this->createEvent('LAN', new \DateTimeImmutable('2026-03-01T10:00:00+00:00'), new \DateTimeImmutable('2026-03-02T18:00:00+00:00'), published: true, isPublic: true);
        $lan = $this->finished($event->getId(), '2026-03-02T18:00:00+00:00');
        $this->eventSlot($lan, $event->getId(), $this->viewer, 'ViewerHK');
        $this->eventSlot($lan, $event->getId(), $this->other, 'OtherHK');
        $this->createUser('stranger@example.org', slug: 'stranger');
        $friendship = Friendship::request($this->other->getId(), $this->viewer->getId(), new \DateTimeImmutable());
        $friendship->accept(new \DateTimeImmutable());
        $this->entityManager->persist($friendship);
        $this->entityManager->flush();

        $data = $this->history('other');
        self::assertIsArray($data);
        self::assertTrue($data['isFriend']);
        self::assertSame(['sent' => 0, 'received' => 0, 'since' => null], $data['items'], 'no feed kept');
        self::assertNull($this->history('stranger'));
        self::assertNull($this->history('viewer'));

        // A block retracts the friendship (FriendshipService::block).
        $this->entityManager->getConnection()->executeStatement('DELETE FROM community_friendship');
        $this->entityManager->persist(Block::create($this->other->getId(), $this->viewer->getId(), new \DateTimeImmutable()));
        $this->entityManager->flush();
        self::assertNull($this->history('other'));
    }

    public function testAnAnonymousVisitorGetsNothing(): void
    {
        $this->client->request('GET', '/api/v1/community/profiles/other/shared-history');
        self::assertResponseStatusCodeSame(401);
    }

    // ─── helpers ────────────────────────────────────────────────────────────────

    private function history(string $slug): mixed
    {
        $this->loginAs($this->viewer);
        $this->client->request('GET', '/api/v1/community/profiles/'.$slug.'/shared-history');
        self::assertResponseStatusCodeSame(200);

        return $this->decodedJsonResponse()['data'] ?? null;
    }

    private function finished(string $eventOrRunId, string $finishedAt): Session
    {
        $end = new \DateTimeImmutable($finishedAt);
        $session = Session::createRunning(bin2hex(random_bytes(16)), $eventOrRunId, 'bridge.local', 38281, 'secret', 5000, $end->modify('-4 hours'));
        $session->transition(Session::STATUS_FINISHED, $end);
        $this->entityManager->persist($session);

        return $session;
    }

    private function eventSlot(Session $session, string $eventId, User $player, string $slotName): void
    {
        $registration = $this->createRegistration($eventId, $player->getId());
        $this->entityManager->persist(SessionSlot::create(bin2hex(random_bytes(16)), $session->getId(), $registration->getId(), $this->gameId, $slotName, 0));
    }

    private function runSlot(Session $session, User $player, string $slotName): void
    {
        $this->entityManager->persist(SessionSlot::create(bin2hex(random_bytes(16)), $session->getId(), $player->getId(), $this->gameId, $slotName, 0));
    }

    private function item(Session $session, string $from, string $to, string $at): SessionFeedEvent
    {
        return new SessionFeedEvent(bin2hex(random_bytes(16)), $session->getId(), SessionFeedEvent::TYPE_ITEM_RECEIVED, $from.' sent an item', new \DateTimeImmutable($at), 1, 'Item', 0, 2, 'Location', 1, $from, 'Game', 2, $to, 'Game');
    }
}
