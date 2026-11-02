<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Community\Domain\Entity\Friendship;
use App\Identity\Domain\Entity\User;
use App\PersonalRuns\Domain\Entity\Run;
use App\Sessions\Domain\Entity\ItemBounty;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Entity\SessionFeedEvent;
use App\Sessions\Domain\Entity\SessionRecap;
use App\Sessions\Domain\Entity\SessionSlot;
use App\Wallet\Application\Query\WeeklyQuestsQueryInterface;
use App\Wallet\Domain\Entity\PelleMovement;
use App\Wallet\Domain\Enum\PelleKind;
use App\Wallet\Domain\Enum\PelleReason;
use App\Wallet\Domain\Enum\QuestMetric;
use App\Wallet\Domain\ValueObject\QuestObjective;
use App\Wallet\Domain\ValueObject\QuestWeek;
use App\WeeklyRuns\Domain\Entity\WeeklyDuel;
use App\WeeklyRuns\Domain\Entity\WeeklyDuelParticipant;
use App\WeeklyRuns\Domain\Entity\WeeklyEntry;
use App\WeeklyRuns\Domain\Entity\WeeklyRun;
use App\WeeklyRuns\Domain\Entity\WeeklyTemplate;

/**
 * Story 41.65: the quest metrics on playing together and on the rest of the site, each read over the quest's week,
 * each with its guard against a second account.
 */
final class QuestCommunityMetricsTest extends FunctionalTestCase
{
    // Week 2026-W40: Monday 2026-09-28 00:00 Paris (2026-09-27T22:00Z) to Monday 2026-10-05.
    private const string IN_THE_WEEK = '2026-10-01T12:00:00+00:00';
    private const string LAST_MONTH = '2026-09-01T12:00:00+00:00';

    public function testADuelCountsOnlyWhenAnOpponentPlayedTheWeekly(): void
    {
        $alice = $this->createUser('alice@example.org', ['ROLE_USER'], 'Alice');
        $bob = $this->createUser('bob@example.org', ['ROLE_USER'], 'Bob');
        $carol = $this->createUser('carol@example.org', ['ROLE_USER'], 'Carol');
        $run = $this->weeklyRun();
        $this->weeklyEntry($alice, $run, 20);
        $this->weeklyEntry($bob, $run, 5);
        $this->weeklyEntry($carol, $run, 0);

        // Alice beats Bob, who played; she also beats Carol, who accepted and never made a check.
        $this->duel($run, $alice, $bob, $alice, self::IN_THE_WEEK);
        $this->duel($run, $alice, $carol, $alice, self::IN_THE_WEEK);
        // Bob's win of last month is not this week's.
        $this->duel($run, $bob, $alice, $bob, self::LAST_MONTH);
        $this->entityManager->flush();

        $duels = new QuestObjective(QuestMetric::DuelsWon, 1);

        self::assertSame([$alice->getId() => 1], $this->counts([$duels])[$duels->key()]);
    }

    public function testSuperlativesCountFromThreeMembersInTheWeekTheRecapIsBuilt(): void
    {
        $alice = $this->createUser('alice@example.org', ['ROLE_USER'], 'Alice');
        $bob = $this->createUser('bob@example.org', ['ROLE_USER'], 'Bob');
        $carol = $this->createUser('carol@example.org', ['ROLE_USER'], 'Carol');

        // Three members: Alice wins two superlatives, Bob one.
        $this->recappedRun(['a1' => $alice, 'b1' => $bob, 'c1' => $carol], [['a1', 'most_generous'], ['a1', 'first_to_goal'], ['b1', 'biggest_hub']], self::IN_THE_WEEK);
        // Two members only: a second account would take everything.
        $this->recappedRun(['a2' => $alice, 'b2' => $bob], [['a2', 'most_generous']], self::IN_THE_WEEK);
        // A recap built last month belongs to last month.
        $this->recappedRun(['a3' => $alice, 'b3' => $bob, 'c3' => $carol], [['a3', 'longest_road']], self::LAST_MONTH);
        $this->entityManager->flush();

        $superlatives = new QuestObjective(QuestMetric::Superlatives, 1);

        self::assertEquals([$alice->getId() => 2, $bob->getId() => 1], $this->counts([$superlatives])[$superlatives->key()], 'by member, in any order');
    }

    public function testFriendsAndReturningPartnersAreTheOtherSideOfNewPartners(): void
    {
        $alice = $this->createUser('alice@example.org', ['ROLE_USER'], 'Alice');
        $bob = $this->createUser('bob@example.org', ['ROLE_USER'], 'Bob');
        $carol = $this->createUser('carol@example.org', ['ROLE_USER'], 'Carol');
        $dave = $this->createUser('dave@example.org', ['ROLE_USER'], 'Dave');

        // Alice and Bob played together last month; this week Alice plays with Bob, Carol and Dave.
        $this->session('s-old', [['A0', $alice], ['B0', $bob]]);
        $this->check('s-old', 'A0', self::LAST_MONTH);
        $this->check('s-old', 'B0', self::LAST_MONTH);
        $this->session('s-1', [['A1', $alice], ['B1', $bob], ['C1', $carol], ['D1', $dave]]);
        foreach (['A1', 'B1', 'C1', 'D1'] as $slot) {
            $this->check('s-1', $slot, self::IN_THE_WEEK);
        }
        // Alice and Carol are friends; Dave's request to Alice is still pending.
        $friendship = Friendship::request($carol->getId(), $alice->getId(), new \DateTimeImmutable(self::LAST_MONTH));
        $friendship->accept(new \DateTimeImmutable(self::LAST_MONTH));
        $this->entityManager->persist($friendship);
        $this->entityManager->persist(Friendship::request($dave->getId(), $alice->getId(), new \DateTimeImmutable(self::LAST_MONTH)));
        $this->entityManager->flush();

        $friends = new QuestObjective(QuestMetric::FriendsPlayedWith, 1);
        $returning = new QuestObjective(QuestMetric::ReturningPartners, 1);
        $counts = $this->counts([$friends, $returning]);

        self::assertEquals([$alice->getId() => 1, $carol->getId() => 1], $counts[$friends->key()], 'by member, in any order');
        self::assertEquals([$alice->getId() => 1, $bob->getId() => 1], $counts[$returning->key()], 'by member, in any order');
    }

    public function testABountyCountsInGoldAndPostedBySomebodyElse(): void
    {
        $alice = $this->createUser('alice@example.org', ['ROLE_USER'], 'Alice');
        $bob = $this->createUser('bob@example.org', ['ROLE_USER'], 'Bob');
        $now = new \DateTimeImmutable(self::IN_THE_WEEK);

        $this->bounty($bob, $alice, $now);
        // A private run's bronze bounty costs nothing; a bounty Alice posted herself is no find.
        $this->bounty($bob, $alice, $now, 'run-1');
        $this->bounty($alice, $alice, $now);
        // Won last month.
        $this->bounty($bob, $alice, new \DateTimeImmutable(self::LAST_MONTH));
        $this->entityManager->flush();

        $bounties = new QuestObjective(QuestMetric::BountiesWon, 1);

        self::assertSame([$alice->getId() => 1], $this->counts([$bounties])[$bounties->key()]);
    }

    public function testEachSourceFoundAtTheBlindTestCountsOnce(): void
    {
        $alice = $this->createUser('alice@example.org', ['ROLE_USER'], 'Alice');
        foreach (['track-1:game', 'track-2:music'] as $source) {
            $this->credit($alice, 'blindtest:event-1:'.$source.':'.$alice->getId(), self::IN_THE_WEEK);
        }
        $this->credit($alice, 'blindtest:event-0:track-9:game:'.$alice->getId(), self::LAST_MONTH);
        $this->entityManager->flush();

        $sources = new QuestObjective(QuestMetric::BlindTestSources, 1);

        self::assertSame([$alice->getId() => 2], $this->counts([$sources])[$sources->key()]);
    }

    /**
     * @param list<QuestObjective> $objectives
     *
     * @return array<string, array<string, int>>
     */
    private function counts(array $objectives): array
    {
        $query = self::getContainer()->get(WeeklyQuestsQueryInterface::class);
        self::assertInstanceOf(WeeklyQuestsQueryInterface::class, $query);

        return $query->counts(QuestWeek::containing(new \DateTimeImmutable(self::IN_THE_WEEK)), $objectives);
    }

    /**
     * @param list<array{0: string, 1: User}> $slots
     */
    private function session(string $sessionId, array $slots): void
    {
        $this->entityManager->persist(Session::create($sessionId, 'event-'.$sessionId, new \DateTimeImmutable(self::LAST_MONTH)));
        foreach ($slots as $index => [$name, $user]) {
            $this->entityManager->persist(SessionSlot::create(bin2hex(random_bytes(16)), $sessionId, $user->getId(), 'game-1', $name, $index + 1, 'slot-'.$sessionId.'-'.$name));
        }
    }

    private function check(string $sessionId, string $slotName, string $at): void
    {
        $this->entityManager->persist(new SessionFeedEvent(
            bin2hex(random_bytes(16)), $sessionId, SessionFeedEvent::TYPE_ITEM_RECEIVED, 'check', new \DateTimeImmutable($at),
            1, 'Item', 0, 2, 'Location', 1, $slotName, 'Game', 2, 'Someone', 'Game',
        ));
    }

    /**
     * A finished personal run session, one slot per member, with a recap built at `$builtAt`.
     *
     * @param array<string, User>         $slots        game slot id => its owner
     * @param list<array{string, string}> $superlatives [winning slot id, key]
     */
    private function recappedRun(array $slots, array $superlatives, string $builtAt): void
    {
        $at = new \DateTimeImmutable($builtAt);
        $run = Run::create(array_values($slots)[0]->getId(), 'Partie entre amis', $at);
        $this->entityManager->persist($run);
        $session = Session::create(bin2hex(random_bytes(16)), $run->getId(), $at);
        foreach ([Session::STATUS_VALIDATING, Session::STATUS_READY, Session::STATUS_GENERATING, Session::STATUS_GENERATED, Session::STATUS_LAUNCHING] as $status) {
            $session->transition($status, $at);
        }
        $session->transition(Session::STATUS_RUNNING, $at, 'bridge.local', 38281, 'secret', 5000);
        $session->transition(Session::STATUS_FINISHED, $at);
        $this->entityManager->persist($session);

        $order = 0;
        foreach ($slots as $slotId => $user) {
            $this->entityManager->persist(SessionSlot::create(bin2hex(random_bytes(16)), $session->getId(), $user->getId(), 'game-1', 'P'.$order, $order, $slotId));
            ++$order;
        }
        $this->entityManager->persist(new SessionRecap($session->getId(), $at, [], [], [], array_map(
            static fn (array $won): array => ['key' => $won[1], 'label' => $won[1], 'slotId' => $won[0], 'value' => 1],
            $superlatives,
        )));
    }

    private function weeklyRun(): WeeklyRun
    {
        $at = new \DateTimeImmutable(self::LAST_MONTH);
        $template = new WeeklyTemplate(
            id: bin2hex(random_bytes(8)),
            gameId: 'game-weekly',
            yamlConfig: "name: ArchiLAN\ngame: Archipelago\n",
            name: null,
            maxAttempts: null,
            isActive: true,
            createdAt: $at,
            updatedAt: $at,
        );
        $this->entityManager->persist($template);
        $run = new WeeklyRun(
            id: bin2hex(random_bytes(8)),
            templateId: $template->getId(),
            weekYear: 2026,
            weekNumber: 40,
            seed: 'archilan-weekly-2026-40',
            status: WeeklyRun::STATUS_FINISHED,
            startedAt: $at,
            createdAt: $at,
        );
        $this->entityManager->persist($run);

        return $run;
    }

    private function weeklyEntry(User $user, WeeklyRun $run, int $checks): void
    {
        $at = new \DateTimeImmutable(self::LAST_MONTH);
        $this->entityManager->persist(new WeeklyEntry(
            bin2hex(random_bytes(16)), $run->getId(), $user->getId(), 1, $at, $at,
            launchedAt: $at,
            goalReachedAt: $checks > 0 ? $at : null,
            checksTotal: $checks > 0 ? $checks : null,
        ));
    }

    private function duel(WeeklyRun $run, User $creator, User $opponent, User $winner, string $resolvedAt): void
    {
        $at = new \DateTimeImmutable($resolvedAt);
        $duel = WeeklyDuel::open($run->getId(), $creator->getId(), $at);
        $challenged = WeeklyDuelParticipant::challenge($duel, $opponent->getId(), $at);
        $challenged->accept($at);
        $duel->resolve($winner->getId(), $at);
        $this->entityManager->persist($duel);
        $this->entityManager->persist(WeeklyDuelParticipant::creator($duel, $at));
        $this->entityManager->persist($challenged);
    }

    private function bounty(User $poster, User $winner, \DateTimeImmutable $at, ?string $runId = null): void
    {
        $bounty = ItemBounty::post('s-1', 'Slot', 'Hookshot', 50, $poster->getId(), $at, $runId);
        $bounty->pay($winner->getId(), $at);
        $this->entityManager->persist($bounty);
    }

    private function credit(User $user, string $key, string $at): void
    {
        $this->entityManager->persist(PelleMovement::record(
            $user->getId(), 5, PelleKind::Gold, null, PelleReason::BlindTest, 'Blind test', null, $key, new \DateTimeImmutable($at),
        ));
    }
}
