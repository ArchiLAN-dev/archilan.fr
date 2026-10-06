<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Community\Application\Support\QuestMetricProvider;
use App\Community\Domain\Entity\Notification;
use App\Identity\Domain\Entity\User;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Entity\SessionFeedEvent;
use App\Sessions\Domain\Entity\SessionSlot;
use App\Wallet\Application\Command\AwardWeeklyQuests;
use App\Wallet\Domain\Entity\PelleMovement;
use App\Wallet\Domain\Entity\QuestDefinition;
use App\Wallet\Domain\Enum\PelleKind;
use App\Wallet\Domain\Enum\PelleReason;
use App\Wallet\Domain\Enum\QuestMetric;
use App\Wallet\Domain\Repository\QuestRepositoryInterface;
use App\Wallet\Domain\ValueObject\QuestObjective;
use App\Wallet\Domain\ValueObject\QuestWeek;
use App\WeeklyRuns\Domain\Entity\WeeklyEntry;

/**
 * Story 41.6: the quests of the week, read from what was actually played. Story 41.15: the quests are written by
 * the admins, drawn for the week, and each objective shows where the member stands.
 */
final class WeeklyQuestsTest extends FunctionalTestCase
{
    // Week 2026-W40: Monday 2026-09-28 to Monday 2026-10-05, Paris time.
    private const string IN_THE_WEEK = '2026-10-01T12:00:00+00:00';
    private const string LAST_MONTH = '2026-09-01T12:00:00+00:00';

    public function testEachQuestPaysTheMembersWhoReallyPlayed(): void
    {
        // The three quests of story 41.6, now written as quests (the default week has three).
        $this->quest('reach_a_goal', 'Atteindre un goal', 40, [new QuestObjective(QuestMetric::Goals, 1)]);
        $this->quest('play_with_someone_new', 'Jouer avec quelqu\'un de nouveau', 30, [new QuestObjective(QuestMetric::NewPartners, 1)]);
        $this->quest('play_a_weekly', 'Faire une hebdo', 30, [new QuestObjective(QuestMetric::Weeklies, 1)]);

        $alice = $this->createUser('alice@example.org', ['ROLE_USER'], 'Alice');
        $bob = $this->createUser('bob@example.org', ['ROLE_USER'], 'Bob');
        $carol = $this->createUser('carol@example.org', ['ROLE_USER'], 'Carol');
        $dave = $this->createUser('dave@example.org', ['ROLE_USER'], 'Dave');
        $eve = $this->createUser('eve@example.org', ['ROLE_USER'], 'Eve');

        // Last month, Alice and Carol already played together.
        $this->session('s-old', [['Alice', $alice], ['Carol', $carol]]);
        $this->check('s-old', 'Alice', self::LAST_MONTH);
        $this->check('s-old', 'Carol', self::LAST_MONTH);

        // This week: Alice plays with Bob for the first time, and reaches her goal; Carol plays with Alice again.
        $this->session('s-new', [['Alice2', $alice], ['Bob', $bob]], goalOf: 'Alice2');
        $this->check('s-new', 'Alice2', self::IN_THE_WEEK);
        $this->check('s-new', 'Bob', self::IN_THE_WEEK);
        $this->session('s-again', [['Alice3', $alice], ['Carol3', $carol]]);
        $this->check('s-again', 'Alice3', self::IN_THE_WEEK);
        $this->check('s-again', 'Carol3', self::IN_THE_WEEK);

        // Dave plays a weekly and finishes it; Eve launches one and never makes a check.
        $this->weeklyEntry($dave, checks: 12, goal: true);
        $this->weeklyEntry($eve, checks: 0, goal: false);
        $this->entityManager->flush();

        $award = $this->award();
        $week = QuestWeek::containing(new \DateTimeImmutable(self::IN_THE_WEEK));

        self::assertSame(5, $award->awardWeek($week));
        self::assertSame(0, $award->awardWeek($week), 'once a week');

        self::assertSame(70, $this->paid($alice), 'a goal (40) and someone new (30)');
        self::assertSame(30, $this->paid($bob), 'someone new');
        self::assertSame(0, $this->paid($carol), 'Alice is not new to her');
        self::assertSame(70, $this->paid($dave), 'a weekly (30) and its goal (40)');
        self::assertSame(0, $this->paid($eve), 'a weekly without a check counts for nothing');

        $movement = $this->entityManager->getRepository(PelleMovement::class)->findOneBy(['userId' => $bob->getId()]);
        self::assertSame(sprintf('quest:%s:play_with_someone_new:%s', $week->key, $bob->getId()), $movement?->getUniqueKey());
    }

    public function testAQuestWithSeveralObjectivesPaysOnlyWhenAllAreReached(): void
    {
        $this->quest('marathon', 'Marathon', 60, [new QuestObjective(QuestMetric::Checks, 2), new QuestObjective(QuestMetric::Sessions, 2)]);

        $alice = $this->createUser('alice@example.org', ['ROLE_USER'], 'Alice');
        $bob = $this->createUser('bob@example.org', ['ROLE_USER'], 'Bob');
        // Alice: two checks in two sessions. Bob: three checks, one session only.
        $this->session('s-1', [['Alice', $alice], ['Bob', $bob]]);
        $this->session('s-2', [['Alice2', $alice]]);
        $this->check('s-1', 'Alice', self::IN_THE_WEEK);
        $this->check('s-2', 'Alice2', self::IN_THE_WEEK);
        foreach ([1, 2, 3] as $ignored) {
            $this->check('s-1', 'Bob', self::IN_THE_WEEK);
        }
        $this->entityManager->flush();

        // The quest, and the chest: it was the week's only quest (story 41.16).
        self::assertSame(2, $this->award()->awardWeek(QuestWeek::containing(new \DateTimeImmutable(self::IN_THE_WEEK))));
        self::assertSame(60 + 50, $this->paid($alice));
        self::assertSame(0, $this->paid($bob), 'enough checks, but one session');
    }

    public function testTheChestPaysOnceThoseWhoDidEveryQuestOfTheWeek(): void
    {
        $this->quest('goal', 'Un goal', 40, [new QuestObjective(QuestMetric::Goals, 1)]);
        $this->quest('checks', 'Deux checks', 20, [new QuestObjective(QuestMetric::Checks, 2)]);
        $alice = $this->createUser('alice@example.org', ['ROLE_USER'], 'Alice');
        $bob = $this->createUser('bob@example.org', ['ROLE_USER'], 'Bob');
        // Alice reaches her goal and makes two checks; Bob only makes the checks.
        $this->session('s-1', [['Alice', $alice], ['Bob', $bob]], goalOf: 'Alice');
        foreach (['Alice', 'Alice', 'Bob', 'Bob'] as $slot) {
            $this->check('s-1', $slot, self::IN_THE_WEEK);
        }
        $this->entityManager->flush();
        $week = QuestWeek::containing(new \DateTimeImmutable(self::IN_THE_WEEK));

        self::assertSame(4, $this->award()->awardWeek($week), 'Alice: two quests and the chest; Bob: one quest');
        self::assertSame(0, $this->award()->awardWeek($week), 'once a week');
        self::assertSame(40 + 20 + 50, $this->paid($alice));
        self::assertSame(20, $this->paid($bob), 'no chest without every quest');
        $chest = $this->entityManager->getRepository(PelleMovement::class)->findOneBy(['uniqueKey' => sprintf('quest-chest:%s:%s', $week->key, $alice->getId())]);
        self::assertSame('Coffre de la semaine', $chest?->getLabel());
    }

    public function testNoChestWhenItIsSetToZero(): void
    {
        $this->quest('goal', 'Un goal', 40, [new QuestObjective(QuestMetric::Goals, 1)]);
        $alice = $this->createUser('alice@example.org', ['ROLE_USER'], 'Alice');
        $this->session('s-1', [['Alice', $alice]], goalOf: 'Alice');
        $this->entityManager->flush();
        $this->settings()->changeChestReward(0);

        self::assertSame(1, $this->award()->awardWeek(QuestWeek::containing(new \DateTimeImmutable(self::IN_THE_WEEK))));
        self::assertSame(40, $this->paid($alice));
    }

    public function testAnObjectiveAimedAtAGameOrAnEventCountsOnlyItsSessions(): void
    {
        $this->quest('hk', 'Un goal sur HK', 40, [new QuestObjective(QuestMetric::Goals, 1, QuestObjective::SCOPE_GAME, 'game-hk')]);
        $this->quest('lan', 'Deux checks à la LAN', 30, [new QuestObjective(QuestMetric::Checks, 2, QuestObjective::SCOPE_EVENT, 'event-s-lan')]);
        $alice = $this->createUser('alice@example.org', ['ROLE_USER'], 'Alice');
        $bob = $this->createUser('bob@example.org', ['ROLE_USER'], 'Bob');
        // Alice reaches her goal on HK; Bob reaches his on another game, and makes his checks at the LAN.
        $this->session('s-hk', [['Alice', $alice]], goalOf: 'Alice', gameId: 'game-hk');
        $this->session('s-other', [['Bob', $bob]], goalOf: 'Bob');
        $this->session('s-lan', [['Bob2', $bob], ['Alice2', $alice]]);
        foreach (['Bob2', 'Bob2', 'Alice2'] as $slot) {
            $this->check('s-lan', $slot, self::IN_THE_WEEK);
        }
        // Checks elsewhere do not count for the LAN.
        $this->check('s-hk', 'Alice', self::IN_THE_WEEK);
        $this->settings()->changeChestReward(0);
        $this->entityManager->flush();

        self::assertSame(2, $this->award()->awardWeek(QuestWeek::containing(new \DateTimeImmutable(self::IN_THE_WEEK))));
        self::assertSame(40, $this->paid($alice), 'her goal on HK; one check at the LAN is not two');
        self::assertSame(30, $this->paid($bob), 'his goal was elsewhere; two checks at the LAN');
    }

    public function testAWeeklyAttemptCountsTheChecksOfItsFeedWithoutItsGoal(): void
    {
        // Story 41.19: before, a weekly only had checks once its goal was reached.
        $this->quest('weekly', 'Faire une hebdo', 30, [new QuestObjective(QuestMetric::Weeklies, 1)]);
        $this->quest('checks', 'Trois checks', 20, [new QuestObjective(QuestMetric::Checks, 3)]);
        $alice = $this->createUser('alice@example.org', ['ROLE_USER'], 'Alice');
        $bob = $this->createUser('bob@example.org', ['ROLE_USER'], 'Bob');
        $week = QuestWeek::containing(new \DateTimeImmutable(self::IN_THE_WEEK));
        // Alice launched her attempt this week and made 3 checks, no goal yet.
        $this->weeklyEntry($alice, checks: 0, goal: false, sessionId: 'weekly-alice');
        foreach ([1, 2, 3] as $ignored) {
            $this->check('weekly-alice', 'Alice', self::IN_THE_WEEK);
        }
        // Bob launched his the week before: his 3 checks of this week count for the checks quest, not as a weekly
        // of this week; his checks of the week before do not count.
        $this->weeklyEntry($bob, checks: 0, goal: false, sessionId: 'weekly-bob', launchedAt: $week->start->modify('-2 days'));
        $this->check('weekly-bob', 'Bob', $week->start->modify('-1 day')->format(\DATE_ATOM));
        foreach ([1, 2, 3] as $ignored) {
            $this->check('weekly-bob', 'Bob', self::IN_THE_WEEK);
        }
        $this->settings()->changeChestReward(0);
        $this->entityManager->flush();

        self::assertSame(3, $this->award()->awardWeek($week));
        self::assertSame(30 + 20, $this->paid($alice));
        self::assertSame(20, $this->paid($bob));
        self::assertSame(2, $this->award()->announce($week->next()), 'a weekly with a check makes an active member for the next week');
    }

    public function testTheNewWeekIsAnnouncedOnceToTheMembersWhoPlayedLately(): void
    {
        $week = QuestWeek::containing(new \DateTimeImmutable(self::IN_THE_WEEK));
        $alice = $this->createUser('alice@example.org', ['ROLE_USER'], 'Alice');
        $old = $this->createUser('old@example.org', ['ROLE_USER'], 'Old');
        // Alice played the week before; Old only months ago.
        $this->session('s-1', [['Alice', $alice], ['Old', $old]]);
        $this->check('s-1', 'Alice', $week->start->modify('-3 days')->format(\DATE_ATOM));
        $this->check('s-1', 'Old', $week->start->modify('-3 months')->format(\DATE_ATOM));
        $this->entityManager->flush();

        self::assertSame(0, $this->award()->announce($week), 'no quest, no announcement');

        $this->quest('goal', 'Un goal', 40, [new QuestObjective(QuestMetric::Goals, 1)]);
        $this->entityManager->flush();
        self::assertSame(1, $this->award()->announce($week));
        self::assertSame(0, $this->award()->announce($week), 'once a week');

        $this->entityManager->clear();
        $notices = $this->entityManager->getRepository(Notification::class)->findBy(['type' => Notification::TYPE_QUESTS_RENEWED]);
        self::assertCount(1, $notices);
        self::assertSame($alice->getId(), $notices[0]->getRecipientId());
        self::assertSame(['week' => $week->key, 'count' => 1, 'maxPelles' => 40 + 50], $notices[0]->getPayload());
    }

    public function testTheWalletShowsTheWeeksBeforeAndTheFactsCountTheQuests(): void
    {
        $member = $this->createUser('member@example.org', ['ROLE_USER'], 'Member');
        $last = QuestWeek::containing(new \DateTimeImmutable())->previous();
        $before = $last->previous();
        foreach ([$last, $before] as $week) {
            $this->ledger($member, sprintf('quest:%s:goal:%s', $week->key, $member->getId()), 40);
            $this->ledger($member, sprintf('quest-chest:%s:%s', $week->key, $member->getId()), 50);
        }
        $this->entityManager->flush();
        $this->loginAs($member);

        $this->client->request('GET', '/api/v1/me/quests');

        self::assertResponseIsSuccessful();
        $history = $this->decodedJsonResponse()['history'] ?? null;
        self::assertIsArray($history);
        self::assertCount(4, $history);
        self::assertSame(
            ['week' => $last->key, 'startsAt' => $last->start->format(\DATE_ATOM), 'endsAt' => $last->end->format(\DATE_ATOM), 'done' => 1, 'served' => 1, 'chest' => true, 'pelles' => 90],
            $history[0],
        );
        self::assertIsArray($history[2]);
        self::assertSame(0, $history[2]['pelles'] ?? null);

        // Story 41.17: the achievement facts count the quests and the run of chests.
        $facts = self::getContainer()->get(QuestMetricProvider::class);
        self::assertInstanceOf(QuestMetricProvider::class, $facts);
        self::assertSame(['questsCompleted' => 2, 'questChestStreak' => 2], $facts->metricsFor($member->getId()));
    }

    public function testTheWalletPageShowsWhereTheMemberStandsOnEachObjective(): void
    {
        $this->quest('marathon', 'Marathon', 60, [new QuestObjective(QuestMetric::Checks, 5), new QuestObjective(QuestMetric::Sessions, 2)]);
        $member = $this->createUser('member@example.org', ['ROLE_USER'], 'Member');
        $now = new \DateTimeImmutable()->format(\DATE_ATOM);
        $this->session('s-now', [['Member', $member]]);
        foreach ([1, 2, 3] as $ignored) {
            $this->check('s-now', 'Member', $now);
        }
        $this->entityManager->flush();
        $this->loginAs($member);

        $this->client->request('GET', '/api/v1/me/quests');

        self::assertResponseIsSuccessful();
        $body = $this->decodedJsonResponse();
        self::assertIsString($body['renewsAt'] ?? null);
        $quests = $body['quests'] ?? null;
        self::assertIsArray($quests);
        self::assertCount(1, $quests);
        self::assertIsArray($quests[0]);
        self::assertSame('marathon', $quests[0]['key'] ?? null);
        self::assertSame('Marathon', $quests[0]['label'] ?? null);
        self::assertFalse($quests[0]['done'] ?? null);
        self::assertSame([
            ['metric' => 'checks', 'label' => 'Checks faits', 'unit' => 'checks', 'target' => 5, 'current' => 3, 'scope' => null],
            ['metric' => 'sessions', 'label' => 'Parties jouées', 'unit' => 'parties', 'target' => 2, 'current' => 1, 'scope' => null],
        ], $quests[0]['objectives'] ?? null);
        self::assertSame(['reward' => 50, 'done' => 0, 'total' => 1, 'paid' => false], $body['chest'] ?? null);
    }

    /**
     * @param list<QuestObjective> $objectives
     */
    private function quest(string $id, string $title, int $reward, array $objectives): void
    {
        $this->entityManager->persist(QuestDefinition::write($title, '', $reward, $objectives, true, 1, new \DateTimeImmutable(self::LAST_MONTH), $id));
    }

    private function ledger(User $user, string $key, int $amount): void
    {
        $this->entityManager->persist(PelleMovement::record(
            $user->getId(), $amount, PelleKind::Gold, null, PelleReason::QuestReward, 'Quête', null, $key, new \DateTimeImmutable(),
        ));
    }

    private function settings(): QuestRepositoryInterface
    {
        $settings = self::getContainer()->get(QuestRepositoryInterface::class);
        self::assertInstanceOf(QuestRepositoryInterface::class, $settings);

        return $settings;
    }

    private function award(): AwardWeeklyQuests
    {
        $award = self::getContainer()->get(AwardWeeklyQuests::class);
        self::assertInstanceOf(AwardWeeklyQuests::class, $award);

        return $award;
    }

    /**
     * @param list<array{0: string, 1: User}> $slots
     */
    private function session(string $sessionId, array $slots, ?string $goalOf = null, string $gameId = 'game-1'): void
    {
        $this->entityManager->persist(Session::create($sessionId, 'event-'.$sessionId, new \DateTimeImmutable(self::LAST_MONTH)));
        foreach ($slots as $index => [$name, $user]) {
            // registrationId holds the member id when no registration row matches (DbalSlotPlayerSource).
            $slot = SessionSlot::create(bin2hex(random_bytes(16)), $sessionId, $user->getId(), $gameId, $name, $index + 1, 'slot-'.$sessionId.'-'.$name);
            if ($name === $goalOf) {
                $slot->recordGoal(new \DateTimeImmutable(self::IN_THE_WEEK));
            }
            $this->entityManager->persist($slot);
        }
    }

    private function check(string $sessionId, string $slotName, string $at): void
    {
        $this->entityManager->persist(new SessionFeedEvent(
            bin2hex(random_bytes(16)), $sessionId, SessionFeedEvent::TYPE_ITEM_RECEIVED, 'check', new \DateTimeImmutable($at),
            1, 'Item', 0, 2, 'Location', 1, $slotName, 'Game', 2, 'Someone', 'Game',
        ));
    }

    private function weeklyEntry(User $user, int $checks, bool $goal, ?string $sessionId = null, ?\DateTimeImmutable $launchedAt = null): void
    {
        $at = $launchedAt ?? new \DateTimeImmutable(self::IN_THE_WEEK);
        $this->entityManager->persist(new WeeklyEntry(
            bin2hex(random_bytes(16)),
            'weekly-1',
            $user->getId(),
            1,
            $at,
            $at,
            externalSessionId: $sessionId,
            launchedAt: $at,
            goalReachedAt: $goal ? $at->modify('+1 hour') : null,
            // As in production, the total is only known once the goal is reached (story 41.19).
            checksTotal: $goal ? $checks : null,
        ));
    }

    private function paid(User $user): int
    {
        return array_sum(array_map(
            static fn (PelleMovement $m): int => $m->getAmount(),
            $this->entityManager->getRepository(PelleMovement::class)->findBy(['userId' => $user->getId(), 'reason' => PelleReason::QuestReward]),
        ));
    }
}
