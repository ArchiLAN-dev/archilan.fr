<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\GameSelection\Domain\Entity\Game;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Entity\SessionSlot;
use App\Shared\Application\Exception\ConflictException;
use App\Wallet\Application\Command\ManageQuests;
use App\Wallet\Application\Query\WeeklyQuestsQueryInterface;
use App\Wallet\Application\Service\QuestWeekPlanner;
use App\Wallet\Domain\Entity\QuestDefinition;
use App\Wallet\Domain\Entity\QuestWeekEntry;
use App\Wallet\Domain\Enum\QuestMetric;
use App\Wallet\Domain\Enum\QuestWeekOrigin;
use App\Wallet\Domain\Repository\QuestRepositoryInterface;
use App\Wallet\Domain\ValueObject\QuestObjective;
use App\Wallet\Domain\ValueObject\QuestWeek;
use App\Wallet\Domain\ValueObject\ServedQuest;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Symfony\Component\Clock\MockClock;

/**
 * Story 41.67: a quest is frozen in its week, and a quest may aim at a game drawn each week it is served.
 */
final class QuestDrawnGameTest extends FunctionalTestCase
{
    // Week 2026-W40: Monday 2026-09-28 00:00 Paris (2026-09-27T22:00Z) to Monday 2026-10-05.
    private const string IN_THE_WEEK = '2026-10-01T12:00:00+00:00';

    public function testAServedQuestKeepsItsObjectivesAndRewardWhenEditedDuringTheWeek(): void
    {
        $quest = $this->quest('marathon', 'Marathon', 60, [new QuestObjective(QuestMetric::Checks, 50)]);
        $this->entityManager->flush();
        $week = $this->week();

        $served = $this->planner()->served($week);
        $quest->edit('Marathon corrigé', '', 90, [new QuestObjective(QuestMetric::Checks, 80)], true, 1);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $again = $this->planner()->served($week);
        self::assertCount(1, $served);
        self::assertCount(1, $again);
        self::assertSame(60, $served[0]->getReward());
        self::assertSame(60, $again[0]->getReward(), 'the week keeps what it served');
        self::assertSame(['checks'], array_map(static fn (QuestObjective $objective): string => $objective->key(), $again[0]->getObjectives()));
        self::assertSame(50, $again[0]->getObjectives()[0]->target ?? null);
        self::assertSame('Marathon corrigé', $again[0]->getTitle(), 'a typo fixed shows at once');
    }

    public function testAnEntryServedBeforeTheSnapshotsReadsItsQuest(): void
    {
        $this->quest('marathon', 'Marathon', 60, [new QuestObjective(QuestMetric::Checks, 50)]);
        $this->entityManager->persist(QuestWeekEntry::serve($this->week()->key, 'marathon', QuestWeekOrigin::Drawn, 0, new \DateTimeImmutable(self::IN_THE_WEEK)));
        $this->entityManager->flush();

        // Read once the week is over: nothing freezes it any more.
        $served = $this->planner('2026-10-20T12:00:00+00:00')->served($this->week());

        self::assertCount(1, $served);
        self::assertSame(60, $served[0]->getReward());
        self::assertNull($served[0]->drawnGameId);
        self::assertFalse($this->repository()->entriesOfWeeks([$this->week()->key])[$this->week()->key][0]->isFrozen(), 'left as it was');
    }

    public function testTheDrawnGameIsDrawnOnceAndCountsAlone(): void
    {
        $hk = $this->played($this->createGame('Hollow Knight', 'hollow-knight'));
        $celeste = $this->played($this->createGame('Celeste', 'celeste'));
        $this->createGame('Never played', 'never-played');
        $this->played($this->createGame('Switched off', 'switched-off'))->disable('Hors service.', new \DateTimeImmutable('2026-09-02T12:00:00+00:00'));
        $this->quest('drawn', 'Jeu de la semaine', 40, [
            new QuestObjective(QuestMetric::Goals, 2, QuestObjective::SCOPE_DRAWN, QuestObjective::POOL_CATALOG),
            new QuestObjective(QuestMetric::Checks, 20, QuestObjective::SCOPE_DRAWN, QuestObjective::POOL_CATALOG),
        ]);
        $this->entityManager->flush();
        $week = $this->week();

        $first = $this->planner(seed: 1)->served($week)[0] ?? null;
        $second = $this->planner(seed: 2)->served($week)[0] ?? null;
        self::assertInstanceOf(ServedQuest::class, $first);
        self::assertNotNull($first->drawnGameId);
        self::assertContains($first->drawnGameId, [$hk->getId(), $celeste->getId()], 'a game online, played and not switched off');
        self::assertSame($first->drawnGameId, $second?->drawnGameId, 'drawn once for the week');
        self::assertSame(
            [sprintf('goals@game:%s', $first->drawnGameId), sprintf('checks@game:%s', $first->drawnGameId)],
            array_map(static fn (QuestObjective $objective): string => $objective->key(), $first->getObjectives()),
            'both objectives on the same drawn game',
        );
    }

    public function testTheDrawAvoidsTheGameDrawnTheLastTimeTheQuestWasServed(): void
    {
        $hk = $this->played($this->createGame('Hollow Knight', 'hollow-knight'));
        $celeste = $this->played($this->createGame('Celeste', 'celeste'));
        $this->quest('drawn', 'Jeu de la semaine', 40, [new QuestObjective(QuestMetric::Goals, 2, QuestObjective::SCOPE_DRAWN, QuestObjective::POOL_CATALOG)]);
        $before = QuestWeekEntry::serve('2026-W30', 'drawn', QuestWeekOrigin::Drawn, 0, new \DateTimeImmutable('2026-07-21T12:00:00+00:00'));
        $before->freeze([new QuestObjective(QuestMetric::Goals, 2, QuestObjective::SCOPE_GAME, $celeste->getId())], 40, $celeste->getId());
        $this->entityManager->persist($before);
        $this->entityManager->flush();

        foreach ([1, 2, 3, 4, 5] as $seed) {
            $this->entityManager->getConnection()->executeStatement("DELETE FROM quest_week_entry WHERE week_key = '2026-W40'");
            $this->entityManager->getConnection()->executeStatement('DELETE FROM quest_week_draw');
            $this->entityManager->clear();
            self::assertSame($hk->getId(), $this->planner(seed: $seed)->served($this->week())[0]->drawnGameId ?? null, 'seed '.$seed);
        }
    }

    public function testAQuestWithNoGameToDrawGivesWayAndCannotBePinned(): void
    {
        $this->played($this->createGame('Old game', 'old-game'));
        $this->quest('fresh', 'Nouveauté', 40, [new QuestObjective(QuestMetric::Goals, 1, QuestObjective::SCOPE_DRAWN, QuestObjective::POOL_RECENT.'7')]);
        $this->quest('marathon', 'Marathon', 60, [new QuestObjective(QuestMetric::Checks, 50)]);
        $this->entityManager->flush();

        $served = $this->planner()->served($this->week());
        self::assertSame(['marathon'], array_map(static fn (ServedQuest $quest): string => $quest->getId(), $served), 'no new release: another quest');

        $this->expectException(ConflictException::class);
        $this->manage()->pin(QuestWeek::containing(new \DateTimeImmutable())->key, 'fresh');
    }

    public function testAQuestPinnedAheadDrawsItsGameWhenTheWeekBeginsFromItsStart(): void
    {
        $next = QuestWeek::containing(new \DateTimeImmutable())->next();
        $fresh = $this->createGame('Fresh', 'fresh');
        $this->entityManager->getConnection()->executeStatement('UPDATE game SET created_at = :at WHERE id = :id', ['at' => $next->start->modify('-10 days')->format(\DATE_ATOM), 'id' => $fresh->getId()]);
        $this->quest('fresh-quest', 'Nouveauté', 40, [new QuestObjective(QuestMetric::Sessions, 1, QuestObjective::SCOPE_DRAWN, QuestObjective::POOL_RECENT.'14')], inDraw: false);
        $this->entityManager->flush();

        $this->manage()->pin($next->key, 'fresh-quest');
        $entry = $this->repository()->entriesOfWeeks([$next->key])[$next->key][0] ?? null;
        self::assertInstanceOf(QuestWeekEntry::class, $entry);
        self::assertFalse($entry->isFrozen(), 'frozen when the week begins');

        $served = $this->planner($next->start->modify('+1 hour')->format(\DATE_ATOM))->served($next);
        self::assertSame($fresh->getId(), $served[0]->drawnGameId ?? null, 'put online 10 days before the week');
    }

    public function testAnObjectiveStillAimingAtAGameToDrawNeverReachesTheCounts(): void
    {
        $this->expectException(\LogicException::class);
        $query = self::getContainer()->get(WeeklyQuestsQueryInterface::class);
        self::assertInstanceOf(WeeklyQuestsQueryInterface::class, $query);
        $query->counts($this->week(), [new QuestObjective(QuestMetric::Goals, 1, QuestObjective::SCOPE_DRAWN, QuestObjective::POOL_CATALOG)]);
    }

    private function week(): QuestWeek
    {
        return QuestWeek::containing(new \DateTimeImmutable(self::IN_THE_WEEK));
    }

    private function planner(string $now = self::IN_THE_WEEK, int $seed = 1): QuestWeekPlanner
    {
        $query = self::getContainer()->get(WeeklyQuestsQueryInterface::class);
        self::assertInstanceOf(WeeklyQuestsQueryInterface::class, $query);

        return new QuestWeekPlanner($this->repository(), new MockClock(new \DateTimeImmutable($now)), new Randomizer(new Mt19937($seed)), $query);
    }

    private function repository(): QuestRepositoryInterface
    {
        $repository = self::getContainer()->get(QuestRepositoryInterface::class);
        self::assertInstanceOf(QuestRepositoryInterface::class, $repository);

        return $repository;
    }

    private function manage(): ManageQuests
    {
        $manage = self::getContainer()->get(ManageQuests::class);
        self::assertInstanceOf(ManageQuests::class, $manage);

        return $manage;
    }

    /**
     * @param list<QuestObjective> $objectives
     */
    private function quest(string $id, string $title, int $reward, array $objectives, bool $inDraw = true): QuestDefinition
    {
        $quest = QuestDefinition::write($title, '', $reward, $objectives, $inDraw, 1, new \DateTimeImmutable('2026-09-01T12:00:00+00:00'), $id);
        $this->entityManager->persist($quest);

        return $quest;
    }

    private function played(Game $game): Game
    {
        $sessionId = 's-'.substr($game->getId(), 0, 8);
        $this->entityManager->persist(Session::create($sessionId, 'event-'.$sessionId, new \DateTimeImmutable('2026-09-01T12:00:00+00:00')));
        $this->entityManager->persist(SessionSlot::create(bin2hex(random_bytes(16)), $sessionId, 'reg-'.$sessionId, $game->getId(), 'P', 1, 'slot-'.$sessionId));

        return $game;
    }
}
