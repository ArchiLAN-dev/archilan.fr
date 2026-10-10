<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Community\Application\Handler\FriendActivityJobHandler;
use App\Community\Application\Message\FriendActivityJob;
use App\Community\Application\Message\SendWebPushJob;
use App\Community\Domain\Entity\CommunityProfile;
use App\Community\Domain\Entity\FriendFavorite;
use App\Community\Domain\Entity\Friendship;
use App\Community\Domain\Entity\Notification;
use App\Community\Domain\Enum\PresenceVisibility;
use App\Events\Domain\Entity\Event;
use App\Identity\Domain\Entity\User;
use App\PersonalRuns\Domain\Entity\Run;
use App\Sessions\Application\Command\RecordSlotGoal;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Entity\SessionSlot;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Story 43.11b: the friends a member starred, announced when they register, launch an event session or reach a goal.
 */
final class FriendActivityTest extends FunctionalTestCase
{
    private User $viewer;

    private User $alice;

    private ?string $gameId = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->viewer = $this->createUser('viewer@example.org', displayName: 'Viewer', slug: 'viewer');
        $this->alice = $this->createUser('alice@example.org', displayName: 'Alice', slug: 'alice');
        $this->star($this->viewer, $this->alice);
    }

    public function testARegistrationIsAnnouncedToWhoStarredTheFriendOncePerHour(): void
    {
        $bob = $this->createUser('bob@example.org', displayName: 'Bob', slug: 'bob');
        $this->befriend($bob, $this->alice);

        $this->loginAs($this->alice);
        // Each request boots a fresh kernel, and its in-memory transport with it: the jobs are run request by request.
        $this->client->jsonRequest('POST', '/api/v1/events/'.$this->upcomingEvent('LAN de printemps')->getId().'/registrations');
        self::assertResponseStatusCodeSame(201);
        $this->processFriendActivity();
        $this->client->jsonRequest('POST', '/api/v1/events/'.$this->upcomingEvent('LAN d\'été')->getId().'/registrations');
        self::assertResponseStatusCodeSame(201);
        $this->processFriendActivity();

        $alerts = $this->alerts($this->viewer);
        self::assertCount(1, $alerts, 'one alert naming the same friend per hour');
        $payload = $alerts[0]->getPayload();
        self::assertSame([$this->alice->getId(), 'Alice', 'registered', 'LAN de printemps'], [$payload['fromUserId'], $payload['actorName'], $payload['kind'], $payload['title']]);
        self::assertSame([], $this->alerts($bob), 'a friend who did not star her hears nothing');
    }

    public function testAFriendHidingTheirPresenceIsNeverAnnounced(): void
    {
        $profile = CommunityProfile::create($this->alice->getId(), new \DateTimeImmutable());
        $profile->choosePresenceVisibility(PresenceVisibility::Nobody, new \DateTimeImmutable());
        $this->entityManager->persist($profile);
        $this->entityManager->flush();

        $this->handle(FriendActivityJob::registered($this->upcomingEvent('LAN')->getId(), $this->alice->getId()));

        self::assertSame([], $this->alerts($this->viewer));
    }

    public function testAnEventLaunchIsAnnouncedToWhoIsNotPlayingItAndARunLaunchIsNot(): void
    {
        $carol = $this->createUser('carol@example.org', displayName: 'Carol', slug: 'carol');
        $this->star($carol, $this->alice);
        $event = $this->createEvent('LAN publique', new \DateTimeImmutable('-1 hour'), new \DateTimeImmutable('+1 day'), published: true);
        $session = $this->eventSession($event, [$this->alice, $this->viewer]);

        $this->handle(FriendActivityJob::sessionStarted($session->getId()));

        self::assertSame([], $this->alerts($this->viewer), 'already playing it');
        $alerts = $this->alerts($carol);
        self::assertCount(1, $alerts);
        self::assertSame(['session_started', 'LAN publique', $event->getId()], [$alerts[0]->getPayload()['kind'], $alerts[0]->getPayload()['title'], $alerts[0]->getPayload()['eventId']]);

        $run = Run::create($this->alice->getId(), 'Run perso', new \DateTimeImmutable());
        $this->entityManager->persist($run);
        $runSession = Session::createRunning(bin2hex(random_bytes(16)), $run->getId(), 'bridge.local', 38281, 'secret', 5000, new \DateTimeImmutable());
        $this->entityManager->persist($runSession);
        $this->entityManager->persist(SessionSlot::create(bin2hex(random_bytes(16)), $runSession->getId(), $this->alice->getId(), $this->gameId(), 'AliceRun', 0));
        $this->entityManager->flush();
        $this->handle(FriendActivityJob::sessionStarted($runSession->getId()));

        self::assertSame([], $this->alerts($this->viewer), 'a personal run waits for the runs open to friends');
    }

    public function testAGoalIsAnnouncedFromTheGoalCallback(): void
    {
        $event = $this->createEvent('LAN publique', new \DateTimeImmutable('-1 hour'), new \DateTimeImmutable('+1 day'), published: true);
        $session = $this->eventSession($event, [$this->alice]);

        $recordGoal = self::getContainer()->get(RecordSlotGoal::class);
        self::assertInstanceOf(RecordSlotGoal::class, $recordGoal);
        $recordGoal->execute($session->getId(), 'Alice', 10, 10, new \DateTimeImmutable());
        $this->processFriendActivity();

        $alerts = $this->alerts($this->viewer);
        self::assertCount(1, $alerts);
        self::assertSame(['goal_reached', 'LAN publique'], [$alerts[0]->getPayload()['kind'], $alerts[0]->getPayload()['title']]);
    }

    public function testNoMoreThanTenAlertsADay(): void
    {
        for ($i = 0; $i < FriendActivityJobHandler::DAILY_CAP; ++$i) {
            $this->entityManager->persist(Notification::create($this->viewer->getId(), Notification::TYPE_FRIEND_ACTIVITY, ['fromUserId' => 'someone'.$i], new \DateTimeImmutable('-2 hours')));
        }
        $this->entityManager->flush();

        $this->handle(FriendActivityJob::registered($this->upcomingEvent('LAN')->getId(), $this->alice->getId()));

        self::assertCount(FriendActivityJobHandler::DAILY_CAP, $this->alerts($this->viewer));
    }

    public function testTheRecipientChoosesBellAndPushBellOnlyOrNothing(): void
    {
        $this->loginAs($this->viewer);
        $this->client->request('GET', '/api/v1/community/notification-preferences');
        self::assertResponseIsSuccessful();
        self::assertSame(
            [['type' => 'friend_activity', 'channel' => 'bell'], ['type' => 'run_invitation', 'channel' => 'bell_push'], ['type' => 'slot_unblocked', 'channel' => 'bell_push'], ['type' => 'run_nudge', 'channel' => 'bell_push']],
            $this->decodedJsonResponse()['data'] ?? null,
        );

        $this->client->jsonRequest('PUT', '/api/v1/community/notification-preferences/friend_activity', ['channel' => 'loud']);
        self::assertResponseStatusCodeSame(422);
        $this->client->jsonRequest('PUT', '/api/v1/community/notification-preferences/comment_received', ['channel' => 'none']);
        self::assertResponseStatusCodeSame(422);

        // Bell only, by default: no push.
        $this->handle(FriendActivityJob::registered($this->upcomingEvent('LAN 1')->getId(), $this->alice->getId()));
        self::assertCount(1, $this->alerts($this->viewer));
        self::assertSame([], $this->pushJobs());

        $this->choose('bell_push');
        $this->clearAlerts();
        $this->handle(FriendActivityJob::registered($this->upcomingEvent('LAN 2')->getId(), $this->alice->getId()));
        self::assertCount(1, $this->alerts($this->viewer));
        self::assertCount(1, $this->pushJobs());

        $this->choose('none');
        $this->clearAlerts();
        $this->handle(FriendActivityJob::registered($this->upcomingEvent('LAN 3')->getId(), $this->alice->getId()));
        self::assertSame([], $this->alerts($this->viewer));
    }

    // ─── helpers ────────────────────────────────────────────────────────────────

    private function star(User $user, User $friend): void
    {
        $this->befriend($user, $friend);
        $this->entityManager->persist(FriendFavorite::create($user->getId(), $friend->getId(), new \DateTimeImmutable()));
        $this->entityManager->flush();
    }

    private function befriend(User $a, User $b): void
    {
        $friendship = Friendship::request($a->getId(), $b->getId(), new \DateTimeImmutable());
        $friendship->accept(new \DateTimeImmutable());
        $this->entityManager->persist($friendship);
        $this->entityManager->flush();
    }

    private function choose(string $channel): void
    {
        $this->loginAs($this->viewer);
        $this->client->jsonRequest('PUT', '/api/v1/community/notification-preferences/friend_activity', ['channel' => $channel]);
        self::assertResponseIsSuccessful();
    }

    private function upcomingEvent(string $title): Event
    {
        return $this->createEvent(
            $title,
            new \DateTimeImmutable('+30 days'),
            new \DateTimeImmutable('+31 days'),
            published: true,
            registrationOpensAt: new \DateTimeImmutable('-1 day'),
            registrationClosesAt: new \DateTimeImmutable('+29 days'),
        );
    }

    private function gameId(): string
    {
        return $this->gameId ??= $this->createGame('Hollow Knight', 'hollow-knight')->getId();
    }

    /**
     * @param list<User> $players
     */
    private function eventSession(Event $event, array $players): Session
    {
        $session = Session::createRunning(bin2hex(random_bytes(16)), $event->getId(), 'bridge.local', 38281, 'secret', 5000, new \DateTimeImmutable());
        $this->entityManager->persist($session);
        foreach ($players as $i => $player) {
            $registration = $this->createRegistration($event->getId(), $player->getId());
            $this->entityManager->persist(SessionSlot::create(bin2hex(random_bytes(16)), $session->getId(), $registration->getId(), $this->gameId(), $player->getDisplayName(), $i));
        }
        $this->entityManager->flush();

        return $session;
    }

    private function handle(FriendActivityJob $job): void
    {
        $handler = self::getContainer()->get(FriendActivityJobHandler::class);
        self::assertInstanceOf(FriendActivityJobHandler::class, $handler);
        $handler($job);
    }

    private function processFriendActivity(): void
    {
        $jobs = 0;
        foreach ($this->transport()->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof FriendActivityJob) {
                $this->handle($message);
                ++$jobs;
            }
        }
        self::assertGreaterThan(0, $jobs, 'the write dispatched its friend activity');
    }

    /**
     * @return list<SendWebPushJob>
     */
    private function pushJobs(): array
    {
        $jobs = [];
        foreach ($this->transport()->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof SendWebPushJob) {
                $jobs[] = $message;
            }
        }

        return $jobs;
    }

    private function transport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    /**
     * @return list<Notification>
     */
    private function alerts(User $recipient): array
    {
        return $this->entityManager->getRepository(Notification::class)->findBy(['recipientId' => $recipient->getId(), 'type' => Notification::TYPE_FRIEND_ACTIVITY]);
    }

    /** Past alerts and sent pushes forgotten, so the hourly cap and the push count start over. */
    private function clearAlerts(): void
    {
        foreach ($this->alerts($this->viewer) as $alert) {
            $this->entityManager->remove($alert);
        }
        $this->entityManager->flush();
        $this->transport()->reset();
    }
}
