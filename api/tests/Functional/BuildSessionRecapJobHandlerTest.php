<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Sessions\Application\Handler\BuildSessionRecapJobHandler;
use App\Sessions\Application\Message\BuildSessionRecapJob;
use App\Sessions\Application\Port\SessionSpoilerArtifactReaderInterface;
use App\Sessions\Application\Port\SpoilerArtifact;
use App\Sessions\Application\Support\RecapSuperlativesCalculator;
use App\Sessions\Application\Support\SpoilerGraphParser;
use App\Sessions\Domain\Session;
use App\Sessions\Domain\SessionRecapRepositoryInterface;
use App\Sessions\Domain\SessionRepositoryInterface;
use App\Sessions\Domain\SessionSlot;
use App\Sessions\Domain\SessionSlotRepositoryInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;

final class BuildSessionRecapJobHandlerTest extends FunctionalTestCase
{
    private const string FIXTURE = __DIR__.'/../Fixtures/Sessions/sample_AP_Spoiler.txt';

    public function testBuildsProjectionFromSpoilerAndReconcilesSlotsToIds(): void
    {
        $this->seedThreePlayerSession();

        $this->runHandler($this->spoilerReaderReturning($this->fixtureContents()));

        $recap = $this->recaps()->findBySessionId('sess-recap');
        self::assertNotNull($recap);

        // Nodes are slot-id-keyed with the game read from the spoiler.
        $nodesBySlot = [];
        foreach ($recap->getNodes() as $node) {
            $nodesBySlot[$node['slotId']] = $node;
        }
        self::assertSame("Luigi's Mansion", $nodesBySlot['slot-p1']['game']);
        self::assertSame('Super Mario 64', $nodesBySlot['slot-p2']['game']);
        self::assertSame('The Wind Waker', $nodesBySlot['slot-p3']['game']);

        // Edges aggregated + remapped to slot ids.
        $edges = [];
        foreach ($recap->getEdges() as $edge) {
            $edges[$edge['fromSlotId'].'->'.$edge['toSlotId']] = $edge['count'];
        }
        self::assertSame(32, $edges['slot-p1->slot-p2']);
        self::assertSame(23, $edges['slot-p1->slot-p3']);
        self::assertSame(26, $edges['slot-p2->slot-p1']);
        self::assertSame(34, $edges['slot-p2->slot-p3']);
        self::assertSame(29, $edges['slot-p3->slot-p1']);
        self::assertSame(28, $edges['slot-p3->slot-p2']);

        $local = [];
        foreach ($recap->getLocalItems() as $item) {
            $local[$item['slotId']] = $item['count'];
        }
        self::assertSame(['slot-p1' => 103, 'slot-p2' => 89, 'slot-p3' => 52], $local);

        // Superlatives resolved to slot ids: Player2 sent the most (60) and finished
        // first; Player3 finished last.
        $superlatives = [];
        foreach ($recap->getSuperlatives() as $s) {
            $superlatives[$s['key']] = $s;
        }
        self::assertSame('slot-p2', $superlatives['most_generous']['slotId']);
        self::assertSame(60, $superlatives['most_generous']['value']);
        self::assertSame('slot-p2', $superlatives['first_to_goal']['slotId']);
        self::assertSame('slot-p3', $superlatives['longest_road']['slotId']);
        self::assertArrayHasKey('biggest_hub', $superlatives);
    }

    public function testMissingSpoilerYieldsStatsOnlyProjection(): void
    {
        $this->seedThreePlayerSession();

        // Reader returns null (unreadable/missing spoiler) -> empty graph, no throw.
        $this->runHandler($this->spoilerReaderReturning(null));

        $recap = $this->recaps()->findBySessionId('sess-recap');
        self::assertNotNull($recap);
        // No spoiler => no exchange graph...
        self::assertSame([], $recap->getNodes());
        self::assertSame([], $recap->getEdges());
        self::assertSame([], $recap->getLocalItems());

        // ...but the time-based superlatives still stand on the slot goal times
        // (they do not need the spoiler); the exchange ones are gone.
        $keys = array_map(static fn (array $s): string => $s['key'], $recap->getSuperlatives());
        sort($keys);
        self::assertSame(['first_to_goal', 'longest_road'], $keys);
    }

    public function testRebuildReplacesTheProjectionInPlace(): void
    {
        $this->seedThreePlayerSession();

        $this->runHandler($this->spoilerReaderReturning(null), '2026-06-01T00:00:00+00:00');
        $this->runHandler($this->spoilerReaderReturning($this->fixtureContents()), '2026-06-02T00:00:00+00:00');

        $all = $this->entityManager
            ->getRepository(\App\Sessions\Domain\SessionRecap::class)
            ->findAll();
        self::assertCount(1, $all, 'rebuild must not create a second projection row');

        $recap = $this->recaps()->findBySessionId('sess-recap');
        self::assertNotNull($recap);
        self::assertCount(6, $recap->getEdges(), 'second build parsed the spoiler');
        self::assertSame('2026-06-02T00:00:00+00:00', $recap->getGeneratedAt()->format(\DateTimeInterface::ATOM));
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
        $session->setGeneratedOutputKey('sess-recap/output/archive.zip');
        $this->entityManager->persist($session);

        // slotName matches the spoiler player names; goal times drive the time-based
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
            $slot->setGoalReachedAt(new \DateTimeImmutable($goalAt));
            $this->entityManager->persist($slot);
        }
        $this->entityManager->flush();
    }

    private function runHandler(SessionSpoilerArtifactReaderInterface $reader, string $clockNow = '2026-06-01T00:00:00+00:00'): void
    {
        $sessions = self::getContainer()->get(SessionRepositoryInterface::class);
        self::assertInstanceOf(SessionRepositoryInterface::class, $sessions);
        $slots = self::getContainer()->get(SessionSlotRepositoryInterface::class);
        self::assertInstanceOf(SessionSlotRepositoryInterface::class, $slots);

        $handler = new BuildSessionRecapJobHandler(
            $sessions,
            $slots,
            $reader,
            new SpoilerGraphParser(),
            new RecapSuperlativesCalculator(),
            $this->recaps(),
            new MockClock(new \DateTimeImmutable($clockNow)),
            new NullLogger(),
        );

        $handler(new BuildSessionRecapJob('sess-recap'));
        $this->entityManager->clear();
    }

    private function spoilerReaderReturning(?string $contents): SessionSpoilerArtifactReaderInterface
    {
        return new readonly class($contents) implements SessionSpoilerArtifactReaderInterface {
            public function __construct(private ?string $contents)
            {
            }

            public function extractSpoiler(string $outputKey): ?SpoilerArtifact
            {
                if (null === $this->contents) {
                    return null;
                }

                return new SpoilerArtifact('x_Spoiler.txt', $this->contents);
            }
        };
    }

    private function recaps(): SessionRecapRepositoryInterface
    {
        $repo = self::getContainer()->get(SessionRecapRepositoryInterface::class);
        self::assertInstanceOf(SessionRecapRepositoryInterface::class, $repo);

        return $repo;
    }

    private function fixtureContents(): string
    {
        $contents = file_get_contents(self::FIXTURE);
        self::assertNotFalse($contents);

        return $contents;
    }
}
