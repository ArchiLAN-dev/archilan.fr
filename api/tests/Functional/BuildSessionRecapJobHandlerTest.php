<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Registrations\Domain\Repository\RegistrationRepositoryInterface;
use App\Sessions\Application\Handler\BuildSessionRecapJobHandler;
use App\Sessions\Application\Message\BuildSessionRecapJob;
use App\Sessions\Application\Port\AchievementRecomputeTriggerInterface;
use App\Sessions\Application\Support\FeedGraphBuilder;
use App\Sessions\Application\Support\RecapSuperlativesCalculator;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Entity\SessionFeedEvent;
use App\Sessions\Domain\Entity\SessionSlot;
use App\Sessions\Domain\Repository\SessionFeedEventRepositoryInterface;
use App\Sessions\Domain\Repository\SessionRecapRepositoryInterface;
use App\Sessions\Domain\Repository\SessionRepositoryInterface;
use App\Sessions\Domain\Repository\SessionSlotRepositoryInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;

/**
 * Story 9.48: the projection is built from the live feed - what was actually played - and no
 * longer from the generation spoiler, which described the seed.
 */
final class BuildSessionRecapJobHandlerTest extends FunctionalTestCase
{
    /** @var list<list<string>> one entry per recomputeForUsers call (story 32.4) */
    private array $recomputeCalls = [];

    /** @var list<string> user ids created by seedThreePlayerSession */
    private array $seededUserIds = [];

    public function testBuildsProjectionFromTheFeedAndReconcilesSlotsToIds(): void
    {
        $this->seedThreePlayerSession();
        $this->seedFeed();

        $this->runHandler();

        $recap = $this->recaps()->findBySessionId('sess-recap');
        self::assertNotNull($recap);

        // Nodes are slot-id-keyed, with the game read from the feed's receiver game.
        $nodesBySlot = [];
        foreach ($recap->getNodes() as $node) {
            $nodesBySlot[$node['slotId']] = $node;
        }
        self::assertSame("Luigi's Mansion", $nodesBySlot['slot-p1']['game']);
        self::assertSame('Super Mario 64', $nodesBySlot['slot-p2']['game']);
        self::assertSame('The Wind Waker', $nodesBySlot['slot-p3']['game']);

        // Edges aggregated per ordered pair, then remapped to slot ids.
        $edges = [];
        foreach ($recap->getEdges() as $edge) {
            $edges[$edge['fromSlotId'].'->'.$edge['toSlotId']] = $edge['count'];
        }
        self::assertSame(3, $edges['slot-p1->slot-p2']);
        self::assertSame(1, $edges['slot-p1->slot-p3']);
        self::assertSame(2, $edges['slot-p2->slot-p1']);
        self::assertArrayNotHasKey('slot-p3->slot-p3', $edges, 'a self-send is a local item, not an edge');

        $local = [];
        foreach ($recap->getLocalItems() as $item) {
            $local[$item['slotId']] = $item['count'];
        }
        self::assertSame(['slot-p3' => 2], $local);

        // Player1 sent the most to others (4); Player2 finished first, Player3 last.
        $superlatives = [];
        foreach ($recap->getSuperlatives() as $s) {
            $superlatives[$s['key']] = $s;
        }
        self::assertSame('slot-p1', $superlatives['most_generous']['slotId']);
        self::assertSame(4, $superlatives['most_generous']['value']);
        self::assertSame('slot-p2', $superlatives['first_to_goal']['slotId']);
        self::assertSame('slot-p3', $superlatives['longest_road']['slotId']);
    }

    public function testEmptyFeedYieldsStatsOnlyProjection(): void
    {
        $this->seedThreePlayerSession();

        // A session that never recorded a feed event (e.g. finished before the feed existed).
        $this->runHandler();

        $recap = $this->recaps()->findBySessionId('sess-recap');
        self::assertNotNull($recap);
        self::assertSame([], $recap->getNodes());
        self::assertSame([], $recap->getEdges());
        self::assertSame([], $recap->getLocalItems());

        // The time-based superlatives still stand on the slot goal times.
        $keys = array_map(static fn (array $s): string => $s['key'], $recap->getSuperlatives());
        sort($keys);
        self::assertSame(['first_to_goal', 'longest_road'], $keys);
    }

    public function testHintAndGoalEventsAreIgnored(): void
    {
        $this->seedThreePlayerSession();
        $this->persistFeedEvent(SessionFeedEvent::TYPE_HINT, 'Player1', 'Player2');
        $this->persistFeedEvent(SessionFeedEvent::TYPE_GOAL, 'Player2', 'Player2');
        $this->entityManager->flush();

        $this->runHandler();

        $recap = $this->recaps()->findBySessionId('sess-recap');
        self::assertNotNull($recap);
        self::assertSame([], $recap->getEdges(), 'only item events feed the exchange graph');
    }

    public function testRebuildReplacesTheProjectionInPlace(): void
    {
        $this->seedThreePlayerSession();

        $this->runHandler('2026-06-01T00:00:00+00:00');
        $this->seedFeed();
        $this->runHandler('2026-06-02T00:00:00+00:00');

        $all = $this->entityManager
            ->getRepository(\App\Sessions\Domain\Entity\SessionRecap::class)
            ->findAll();
        self::assertCount(1, $all, 'rebuild must not create a second projection row');

        $recap = $this->recaps()->findBySessionId('sess-recap');
        self::assertNotNull($recap);
        self::assertCount(3, $recap->getEdges(), 'second build read the feed');
        self::assertSame('2026-06-02T00:00:00+00:00', $recap->getGeneratedAt()->format(\DateTimeInterface::ATOM));
    }

    public function testSuccessfulBuildTriggersAchievementRecomputeForParticipants(): void
    {
        $this->seedThreePlayerSession();
        $this->seedFeed();

        $this->runHandler();

        self::assertCount(1, $this->recomputeCalls, 'exactly one recompute per build');
        $notified = $this->recomputeCalls[0];
        sort($notified);
        $expected = $this->seededUserIds;
        sort($expected);
        self::assertSame($expected, $notified);
    }

    public function testNoRecomputeWhenTheBuildAbortsEarly(): void
    {
        $this->seedThreePlayerSession();

        $this->runHandler(sessionId: 'sess-unknown');

        self::assertSame([], $this->recomputeCalls);
    }

    private function seedThreePlayerSession(): void
    {
        $now = new \DateTimeImmutable('2026-05-01T10:00:00+00:00');
        $event = $this->createEvent('ArchiLAN Recap', $now, $now->modify('+2 days'));
        $game = $this->createGame('Multi', 'multi');

        $session = Session::create('sess-recap', $event->getId(), $now);
        foreach ([
            Session::STATUS_VALIDATING, Session::STATUS_READY, Session::STATUS_GENERATING,
            Session::STATUS_GENERATED, Session::STATUS_LAUNCHING,
        ] as $status) {
            $session->transition($status, $now);
        }
        $session->transition(Session::STATUS_RUNNING, $now, 'bridge.local', 38281, 'secret', 5000);
        $session->transition(Session::STATUS_FINISHED, $now->modify('+2 hours'));
        $session->markGenerated('sess-recap/output/archive.zip');
        $this->entityManager->persist($session);

        // slotName matches the feed player names; goal times drive the time-based
        // superlatives (Player2 first, Player3 last).
        $goals = [
            'Player1' => '2026-05-01T11:03:00+00:00',
            'Player2' => '2026-05-01T11:01:00+00:00',
            'Player3' => '2026-05-01T11:05:00+00:00',
        ];
        $order = 0;
        foreach ($goals as $slotName => $goalAt) {
            ++$order;
            $user = $this->createUser(strtolower($slotName).'@example.org', displayName: $slotName);
            $this->seededUserIds[] = $user->getId();
            $reg = $this->createRegistration($event->getId(), $user->getId());
            $slot = SessionSlot::create(
                bin2hex(random_bytes(16)),
                $session->getId(),
                $reg->getId(),
                $game->getId(),
                $slotName,
                $order - 1,
                'slot-p'.$order,
            );
            $slot->recordGoal(new \DateTimeImmutable($goalAt));
            $this->entityManager->persist($slot);
        }
        $this->entityManager->flush();
    }

    private const array GAMES = [
        'Player1' => "Luigi's Mansion",
        'Player2' => 'Super Mario 64',
        'Player3' => 'The Wind Waker',
    ];

    /**
     * Seeds through the entity's own type constants, never a literal. The first cut of this test
     * invented `'item'` - a type the bridge never sends - so it agreed with the reader's own bug
     * and every real session produced an empty graph. The writer's vocabulary is proven against a
     * real bridge payload in SessionFeedEndpointTest; sharing the constant closes the chain.
     */
    private function seedFeed(): void
    {
        $sends = [
            ['Player1', 'Player2'],
            ['Player1', 'Player2'],
            ['Player1', 'Player2'],
            ['Player1', 'Player3'],
            ['Player2', 'Player1'],
            ['Player2', 'Player1'],
            // Self-sends are local items, not edges.
            ['Player3', 'Player3'],
            ['Player3', 'Player3'],
        ];
        foreach ($sends as [$from, $to]) {
            $this->persistFeedEvent(SessionFeedEvent::TYPE_ITEM_RECEIVED, $from, $to);
        }
        $this->entityManager->flush();
    }

    private function persistFeedEvent(string $type, string $sender, string $receiver): void
    {
        $this->entityManager->persist(new SessionFeedEvent(
            bin2hex(random_bytes(16)),
            'sess-recap',
            $type,
            sprintf('%s sent an item to %s', $sender, $receiver),
            new \DateTimeImmutable('2026-05-01T10:30:00+00:00'),
            null,
            'Some Item',
            null,
            null,
            'Some Location',
            null,
            $sender,
            self::GAMES[$sender],
            null,
            $receiver,
            self::GAMES[$receiver],
        ));
    }

    private function runHandler(
        string $clockNow = '2026-06-01T00:00:00+00:00',
        string $sessionId = 'sess-recap',
    ): void {
        $sessions = self::getContainer()->get(SessionRepositoryInterface::class);
        self::assertInstanceOf(SessionRepositoryInterface::class, $sessions);
        $slots = self::getContainer()->get(SessionSlotRepositoryInterface::class);
        self::assertInstanceOf(SessionSlotRepositoryInterface::class, $slots);
        $registrations = self::getContainer()->get(RegistrationRepositoryInterface::class);
        self::assertInstanceOf(RegistrationRepositoryInterface::class, $registrations);
        $feedEvents = self::getContainer()->get(SessionFeedEventRepositoryInterface::class);
        self::assertInstanceOf(SessionFeedEventRepositoryInterface::class, $feedEvents);

        $spy = $this->recomputeSpy();
        $handler = new BuildSessionRecapJobHandler(
            $sessions,
            $slots,
            $feedEvents,
            new FeedGraphBuilder(),
            new RecapSuperlativesCalculator(),
            $this->recaps(),
            $registrations,
            $spy,
            new MockClock(new \DateTimeImmutable($clockNow)),
            new NullLogger(),
        );

        $handler(new BuildSessionRecapJob($sessionId));
        $this->recomputeCalls = [...$this->recomputeCalls, ...$spy->calls];
        $this->entityManager->clear();
    }

    /**
     * @return AchievementRecomputeTriggerInterface&object{calls: list<list<string>>}
     */
    private function recomputeSpy(): AchievementRecomputeTriggerInterface
    {
        return new class implements AchievementRecomputeTriggerInterface {
            /** @var list<list<string>> */
            public array $calls = [];

            public function recomputeForUsers(array $userIds): void
            {
                $this->calls[] = $userIds;
            }
        };
    }

    private function recaps(): SessionRecapRepositoryInterface
    {
        $repo = self::getContainer()->get(SessionRecapRepositoryInterface::class);
        self::assertInstanceOf(SessionRecapRepositoryInterface::class, $repo);

        return $repo;
    }
}
