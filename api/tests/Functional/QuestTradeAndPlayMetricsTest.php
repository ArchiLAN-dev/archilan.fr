<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Domain\Entity\User;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Entity\SessionFeedEvent;
use App\Sessions\Domain\Entity\SessionSlot;
use App\Sessions\Domain\Entity\SlotPlaySpan;
use App\Wallet\Application\Query\WeeklyQuestsQueryInterface;
use App\Wallet\Domain\Enum\QuestMetric;
use App\Wallet\Domain\ValueObject\QuestObjective;
use App\Wallet\Domain\ValueObject\QuestWeek;
use App\WeeklyRuns\Domain\Entity\WeeklyEntry;
use App\WeeklyRuns\Domain\Entity\WeeklyRun;
use App\WeeklyRuns\Domain\Entity\WeeklyTemplate;

/**
 * Story 41.64: the quest metrics on items traded between members, time played and games played for the first time,
 * each read over the quest's week only.
 */
final class QuestTradeAndPlayMetricsTest extends FunctionalTestCase
{
    // Week 2026-W40: Monday 2026-09-28 00:00 Paris (2026-09-27T22:00Z) to Monday 2026-10-05.
    private const string IN_THE_WEEK = '2026-10-01T12:00:00+00:00';
    private const string LAST_MONTH = '2026-09-01T12:00:00+00:00';

    public function testItemsCountOnlyWhatAMemberFoundForAnotherMember(): void
    {
        $alice = $this->createUser('alice@example.org', ['ROLE_USER'], 'Alice');
        $bob = $this->createUser('bob@example.org', ['ROLE_USER'], 'Bob');
        $this->session('s-1', [['Alice', $alice], ['Alice2', $alice], ['Bob', $bob]]);
        $bobSlot = $this->session('s-2', [['Alice3', $alice], ['Bob3', $bob]])['Bob3'];

        // Three items found by Alice for Bob this week; one for her own second slot; one last month.
        foreach ([1, 2, 3] as $minute) {
            $this->item('s-1', 'Alice', 'Bob', sprintf('2026-10-01T12:%02d:00+00:00', $minute));
        }
        $this->item('s-1', 'Alice', 'Alice2', self::IN_THE_WEEK);
        $this->item('s-1', 'Alice', 'Bob', self::LAST_MONTH);
        // Bob finds one item for Alice, then releases: what his release sends is nobody's find.
        $this->item('s-2', 'Bob3', 'Alice3', '2026-10-01T13:00:00+00:00');
        $bobSlot->recordHandOver(false, new \DateTimeImmutable('2026-10-01T14:00:00+00:00'));
        $this->item('s-2', 'Bob3', 'Alice3', '2026-10-01T14:00:05+00:00');
        $this->item('s-2', 'Bob3', 'Alice3', '2026-10-01T14:00:05+00:00');
        $this->entityManager->flush();

        $sent = new QuestObjective(QuestMetric::ItemsSent, 1);
        $received = new QuestObjective(QuestMetric::ItemsReceived, 1);
        $counts = $this->counts([$sent, $received]);

        self::assertEquals([$alice->getId() => 3, $bob->getId() => 1], $counts[$sent->key()], 'by member, in any order');
        self::assertEquals([$alice->getId() => 1, $bob->getId() => 3], $counts[$received->key()], 'by member, in any order');
    }

    public function testAnItemObjectiveOnAGameCountsTheGameOfTheMembersOwnSlot(): void
    {
        $alice = $this->createUser('alice@example.org', ['ROLE_USER'], 'Alice');
        $bob = $this->createUser('bob@example.org', ['ROLE_USER'], 'Bob');
        // A multiworld: Alice plays Hollow Knight, Bob another game.
        $this->session('s-1', [['Alice', $alice]], gameId: 'game-hk');
        $this->slot('s-1', 'Bob', $bob, 'game-other', 2);
        $this->item('s-1', 'Alice', 'Bob', self::IN_THE_WEEK);
        $this->item('s-1', 'Bob', 'Alice', self::IN_THE_WEEK);
        $this->entityManager->flush();

        $sent = new QuestObjective(QuestMetric::ItemsSent, 1, QuestObjective::SCOPE_GAME, 'game-hk');
        $received = new QuestObjective(QuestMetric::ItemsReceived, 1, QuestObjective::SCOPE_GAME, 'game-hk');
        $counts = $this->counts([$sent, $received]);

        self::assertSame([$alice->getId() => 1], $counts[$sent->key()], 'Alice found it from her Hollow Knight slot; Bob did not play it');
        self::assertSame([$alice->getId() => 1], $counts[$received->key()], 'Alice received it on her Hollow Knight slot');
    }

    public function testPlayTimeCountsTheWeeksShareOfTheSlotsThatMadeACheck(): void
    {
        $alice = $this->createUser('alice@example.org', ['ROLE_USER'], 'Alice');
        $bob = $this->createUser('bob@example.org', ['ROLE_USER'], 'Bob');
        $this->session('s-1', [['Alice', $alice], ['Bob', $bob]]);
        // Alice: from Sunday 21:00 UTC to 23:30 UTC, half an hour before the week and 90 minutes in it; then 2 h on Thursday.
        $this->span('s-1', 'Alice', '2026-09-27T21:30:00+00:00', '2026-09-27T23:30:00+00:00');
        $this->span('s-1', 'Alice', '2026-10-01T10:00:00+00:00', '2026-10-01T12:00:00+00:00');
        $this->check('s-1', 'Alice', self::IN_THE_WEEK);
        // Bob leaves his client open for three hours without a single check.
        $this->span('s-1', 'Bob', '2026-10-01T10:00:00+00:00', '2026-10-01T13:00:00+00:00');
        $this->entityManager->flush();

        $time = new QuestObjective(QuestMetric::PlayMinutes, 60);
        $elsewhere = new QuestObjective(QuestMetric::PlayMinutes, 60, QuestObjective::SCOPE_GAME, 'game-hk');
        $counts = $this->counts([$time, $elsewhere]);

        self::assertSame([$alice->getId() => 210], $counts[$time->key()]);
        self::assertSame([], $counts[$elsewhere->key()], 'none of it on Hollow Knight');
    }

    public function testNewGamesCountTheFirstCheckOfALifeOnAGame(): void
    {
        $alice = $this->createUser('alice@example.org', ['ROLE_USER'], 'Alice');
        $bob = $this->createUser('bob@example.org', ['ROLE_USER'], 'Bob');
        $carol = $this->createUser('carol@example.org', ['ROLE_USER'], 'Carol');

        // Alice played game-1 last month; this week game-1 again, game-2 and game-3.
        $this->session('s-old', [['Alice', $alice]]);
        $this->check('s-old', 'Alice', self::LAST_MONTH);
        $this->session('s-1', [['A1', $alice]]);
        $this->check('s-1', 'A1', self::IN_THE_WEEK);
        $this->session('s-2', [['A2', $alice]], gameId: 'game-2');
        $this->check('s-2', 'A2', self::IN_THE_WEEK);
        $this->session('s-3', [['A3', $alice]], gameId: 'game-3');
        $this->check('s-3', 'A3', self::IN_THE_WEEK);

        // Bob discovers the weekly's game this week; Carol had played it in a weekly last month.
        $run = $this->weeklyRun('game-weekly');
        $this->weeklyEntry($bob, $run, 'w-bob', new \DateTimeImmutable(self::IN_THE_WEEK));
        $this->check('w-bob', 'Bob', self::IN_THE_WEEK);
        $this->weeklyEntry($carol, $run, 'w-carol', new \DateTimeImmutable(self::LAST_MONTH));
        $this->check('w-carol', 'Carol', self::LAST_MONTH);
        $this->session('s-carol', [['Carol', $carol]], gameId: 'game-weekly');
        $this->check('s-carol', 'Carol', self::IN_THE_WEEK);
        $this->entityManager->flush();

        $games = new QuestObjective(QuestMetric::NewGames, 1);

        self::assertEquals([$alice->getId() => 2, $bob->getId() => 1], $this->counts([$games])[$games->key()], 'by member, in any order');
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
     *
     * @return array<string, SessionSlot>
     */
    private function session(string $sessionId, array $slots, string $gameId = 'game-1'): array
    {
        $this->entityManager->persist(Session::create($sessionId, 'event-'.$sessionId, new \DateTimeImmutable(self::LAST_MONTH)));
        $made = [];
        foreach ($slots as $index => [$name, $user]) {
            $made[$name] = $this->slot($sessionId, $name, $user, $gameId, $index + 1);
        }

        return $made;
    }

    private function slot(string $sessionId, string $name, User $user, string $gameId, int $index): SessionSlot
    {
        // registrationId holds the member id when no registration row matches (DbalSlotPlayerSource).
        $slot = SessionSlot::create(bin2hex(random_bytes(16)), $sessionId, $user->getId(), $gameId, $name, $index, 'slot-'.$sessionId.'-'.$name);
        $this->entityManager->persist($slot);

        return $slot;
    }

    private function check(string $sessionId, string $slotName, string $at): void
    {
        $this->item($sessionId, $slotName, 'Someone', $at);
    }

    private function item(string $sessionId, string $sender, string $receiver, string $at): void
    {
        $this->entityManager->persist(new SessionFeedEvent(
            bin2hex(random_bytes(16)), $sessionId, SessionFeedEvent::TYPE_ITEM_RECEIVED, 'check', new \DateTimeImmutable($at),
            1, 'Item', 0, 2, 'Location', 1, $sender, 'Game', 2, $receiver, 'Game',
        ));
    }

    private function span(string $sessionId, string $slotName, string $from, string $to): void
    {
        $span = SlotPlaySpan::open(bin2hex(random_bytes(16)), $sessionId, $slotName, new \DateTimeImmutable($from));
        $span->extendTo(new \DateTimeImmutable($to));
        $this->entityManager->persist($span);
    }

    private function weeklyRun(string $gameId): WeeklyRun
    {
        $at = new \DateTimeImmutable(self::LAST_MONTH);
        $template = new WeeklyTemplate(
            id: bin2hex(random_bytes(8)),
            gameId: $gameId,
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
            weekNumber: 36,
            seed: 'archilan-weekly-2026-36',
            status: WeeklyRun::STATUS_FINISHED,
            startedAt: $at,
            createdAt: $at,
        );
        $this->entityManager->persist($run);

        return $run;
    }

    private function weeklyEntry(User $user, WeeklyRun $run, string $sessionId, \DateTimeImmutable $at): void
    {
        $this->entityManager->persist(new WeeklyEntry(
            bin2hex(random_bytes(16)), $run->getId(), $user->getId(), 1, $at, $at,
            externalSessionId: $sessionId,
            launchedAt: $at,
        ));
    }
}
