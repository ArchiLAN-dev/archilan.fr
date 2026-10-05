<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Domain\Entity\User;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Entity\SessionFeedEvent;
use App\Sessions\Domain\Entity\SessionSlot;
use App\Wallet\Application\Command\AwardWeeklyQuests;
use App\Wallet\Domain\Entity\PelleMovement;
use App\Wallet\Domain\Entity\QuestDefinition;
use App\Wallet\Domain\Enum\PelleReason;
use App\Wallet\Domain\Enum\QuestMetric;
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

        self::assertSame(1, $this->award()->awardWeek(QuestWeek::containing(new \DateTimeImmutable(self::IN_THE_WEEK))));
        self::assertSame(60, $this->paid($alice));
        self::assertSame(0, $this->paid($bob), 'enough checks, but one session');
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
            ['metric' => 'checks', 'label' => 'Checks faits', 'unit' => 'checks', 'target' => 5, 'current' => 3],
            ['metric' => 'sessions', 'label' => 'Parties jouées', 'unit' => 'parties', 'target' => 2, 'current' => 1],
        ], $quests[0]['objectives'] ?? null);
    }

    /**
     * @param list<QuestObjective> $objectives
     */
    private function quest(string $id, string $title, int $reward, array $objectives): void
    {
        $this->entityManager->persist(QuestDefinition::write($title, '', $reward, $objectives, true, new \DateTimeImmutable(self::LAST_MONTH), $id));
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
    private function session(string $sessionId, array $slots, ?string $goalOf = null): void
    {
        $this->entityManager->persist(Session::create($sessionId, 'event-'.$sessionId, new \DateTimeImmutable(self::LAST_MONTH)));
        foreach ($slots as $index => [$name, $user]) {
            // registrationId holds the member id when no registration row matches (DbalSlotPlayerSource).
            $slot = SessionSlot::create(bin2hex(random_bytes(16)), $sessionId, $user->getId(), 'game-1', $name, $index + 1, 'slot-'.$sessionId.'-'.$name);
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

    private function weeklyEntry(User $user, int $checks, bool $goal): void
    {
        $at = new \DateTimeImmutable(self::IN_THE_WEEK);
        $this->entityManager->persist(new WeeklyEntry(
            bin2hex(random_bytes(16)),
            'weekly-1',
            $user->getId(),
            1,
            $at,
            $at,
            launchedAt: $at,
            goalReachedAt: $goal ? $at->modify('+1 hour') : null,
            checksTotal: $checks,
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
