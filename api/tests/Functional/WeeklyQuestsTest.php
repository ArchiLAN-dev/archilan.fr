<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Domain\Entity\User;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Entity\SessionFeedEvent;
use App\Sessions\Domain\Entity\SessionSlot;
use App\Wallet\Application\Command\AwardWeeklyQuests;
use App\Wallet\Domain\Entity\PelleMovement;
use App\Wallet\Domain\Enum\PelleReason;
use App\Wallet\Domain\ValueObject\QuestWeek;
use App\WeeklyRuns\Domain\Entity\WeeklyEntry;

/**
 * Story 41.6: the quests of the week, read from what was actually played.
 */
final class WeeklyQuestsTest extends FunctionalTestCase
{
    // Week 2026-W40: Monday 2026-09-28 to Monday 2026-10-05, Paris time.
    private const string IN_THE_WEEK = '2026-10-01T12:00:00+00:00';
    private const string LAST_MONTH = '2026-09-01T12:00:00+00:00';

    public function testEachQuestPaysTheMembersWhoReallyPlayed(): void
    {
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

        $award = self::getContainer()->get(AwardWeeklyQuests::class);
        self::assertInstanceOf(AwardWeeklyQuests::class, $award);
        $week = QuestWeek::containing(new \DateTimeImmutable(self::IN_THE_WEEK));

        self::assertSame(5, $award->awardWeek($week));
        self::assertSame(0, $award->awardWeek($week), 'once a week');

        self::assertSame(70, $this->paid($alice), 'a goal (40) and someone new (30)');
        self::assertSame(30, $this->paid($bob), 'someone new');
        self::assertSame(0, $this->paid($carol), 'Alice is not new to her');
        self::assertSame(70, $this->paid($dave), 'a weekly (30) and its goal (40)');
        self::assertSame(0, $this->paid($eve), 'a weekly without a check counts for nothing');
    }

    public function testTheWalletPageShowsTheQuestsOfTheWeek(): void
    {
        $member = $this->createUser('member@example.org', ['ROLE_USER'], 'Member');
        $this->loginAs($member);

        $this->client->request('GET', '/api/v1/me/quests');

        self::assertResponseIsSuccessful();
        $body = $this->decodedJsonResponse();
        $quests = $body['quests'] ?? null;
        self::assertIsArray($quests);
        self::assertCount(3, $quests);
        self::assertSame(['key' => 'reach_a_goal', 'label' => 'Atteindre un goal', 'reward' => 40, 'done' => false, 'paid' => false], $quests[0]);
        self::assertIsString($body['renewsAt'] ?? null);
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
