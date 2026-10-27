<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Community\Application\Command\RecomputeAchievements;
use App\Community\Application\Support\MemberStatsLayout;
use App\Community\Domain\Entity\MemberStatsSnapshot;
use App\Community\Domain\Repository\MemberStatsSnapshotRepositoryInterface;
use App\Identity\Application\Query\PlayerGameStatsQueryInterface;
use App\Identity\Application\Query\PlayerStatsQueryInterface;
use App\Identity\Domain\Entity\User;
use App\PersonalRuns\Domain\Entity\Run;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Entity\SessionFeedEvent;
use App\Sessions\Domain\Entity\SessionSlot;
use App\Sessions\Domain\Entity\SlotPlaySpan;
use App\WeeklyRuns\Domain\Entity\WeeklyEntry;
use App\WeeklyRuns\Domain\Entity\WeeklyRun;
use App\WeeklyRuns\Domain\Entity\WeeklyTemplate;

/**
 * Story 30.55: the public stats page - the facts kept by the achievement recompute, grouped by theme, the play
 * time in seconds, and a table by game counted by the profile's own rules.
 */
final class MemberStatsTest extends FunctionalTestCase
{
    private const string NOW = '2026-10-01T10:00:00+00:00';

    public function testTheRecomputeKeepsTheFactsItBuilt(): void
    {
        $user = $this->createUser('jean@example.org', ['ROLE_USER'], 'Jean', 'jean');
        $this->playedEverything($user);

        $this->recompute()->recomputeForUser($user->getId(), notify: false);
        $this->recompute()->recomputeForUser($user->getId(), notify: false);

        $this->entityManager->clear();
        $snapshot = $this->entityManager->find(MemberStatsSnapshot::class, $user->getId());
        self::assertInstanceOf(MemberStatsSnapshot::class, $snapshot);
        self::assertSame(2, $snapshot->getFacts()['runs'] ?? null);
        self::assertSame(1, $snapshot->getFacts()['deathLinkDeaths'] ?? null);
    }

    public function testStoringTwiceReplacesTheFacts(): void
    {
        $repository = self::getContainer()->get(MemberStatsSnapshotRepositoryInterface::class);
        self::assertInstanceOf(MemberStatsSnapshotRepositoryInterface::class, $repository);

        $repository->store('user-1', ['runs' => 1], new \DateTimeImmutable('2026-10-27T10:00:00+00:00'));
        $repository->store('user-1', ['runs' => 2, 'goals' => 1], new \DateTimeImmutable('2026-10-27T11:00:00+00:00'));

        $this->entityManager->clear();
        $snapshot = $repository->findByUserId('user-1');
        self::assertInstanceOf(MemberStatsSnapshot::class, $snapshot);
        self::assertSame(['runs' => 2, 'goals' => 1], $snapshot->getFacts());
        self::assertSame('2026-10-27T11:00:00+00:00', $snapshot->getComputedAt()->format(\DATE_ATOM));
    }

    public function testThePageGroupsTheFactsAndListsTheGames(): void
    {
        $user = $this->createUser('jean@example.org', ['ROLE_USER'], 'Jean', 'jean');
        $this->playedEverything($user);
        $this->recompute()->recomputeForUser($user->getId(), notify: false);

        $this->client->request('GET', '/api/v1/community/profiles/jean/stats');
        self::assertResponseStatusCodeSame(200);
        $data = $this->decodedJsonResponse()['data'];
        self::assertIsArray($data);

        self::assertSame('jean', $data['slug']);
        self::assertIsString($data['computedAt']);

        self::assertIsArray($data['groups']);
        $groups = [];
        foreach ($data['groups'] as $group) {
            self::assertIsArray($group);
            self::assertIsString($group['key']);
            self::assertIsArray($group['facts']);
            $groups[$group['key']] = $group['facts'];
        }
        self::assertSame(['games', 'playtime', 'progress', 'deathlink', 'social', 'weekly', 'recaps'], array_keys($groups));

        $runs = $groups['games'][0];
        self::assertIsArray($runs);
        self::assertSame(['key' => 'runs', 'label' => 'Parties jouées', 'value' => 2, 'unit' => null], $runs);
        self::assertSame(['key' => 'playedSeconds', 'label' => 'Temps connecté sur les parties (total)', 'value' => 3600, 'unit' => 'seconds'], $groups['playtime'][0]);

        self::assertIsArray($data['superlatives']);
        self::assertCount(4, $data['superlatives']);

        self::assertIsArray($data['games']);
        self::assertCount(2, $data['games']);
        $first = $data['games'][0];
        self::assertIsArray($first);
        self::assertSame('Hollow Knight', $first['name'], 'the most played first');
        self::assertSame(3600, $first['playedSeconds']);
        self::assertSame(1, $first['deaths']);
        self::assertSame(1, $first['runs']);
        self::assertSame(50, $first['checks'], 'the released slot without goal is left out');
        $second = $data['games'][1];
        self::assertIsArray($second);
        self::assertSame("Luigi's Mansion", $second['name']);
        self::assertSame(2, $second['runs'], 'the event run and the weekly attempt');
        self::assertSame(866, $second['checks']);
    }

    public function testAMemberNeverRecomputedReadsAsZeros(): void
    {
        $this->createUser('jean@example.org', ['ROLE_USER'], 'Jean', 'jean');

        $this->client->request('GET', '/api/v1/community/profiles/jean/stats');
        self::assertResponseStatusCodeSame(200);
        $data = $this->decodedJsonResponse()['data'];
        self::assertIsArray($data);
        self::assertNull($data['computedAt']);
        self::assertIsArray($data['groups']);
        $games = $data['groups'][0];
        self::assertIsArray($games);
        self::assertIsArray($games['facts']);
        foreach ($games['facts'] as $fact) {
            self::assertIsArray($fact);
            self::assertSame(0, $fact['value']);
        }
        self::assertSame([], $data['games']);
    }

    public function testAnUnknownPlayerIsNotFound(): void
    {
        $this->client->request('GET', '/api/v1/community/profiles/nobody/stats');

        self::assertResponseStatusCodeSame(404);
    }

    public function testTheGamesAddUpToTheProfileTotals(): void
    {
        $user = $this->createUser('jean@example.org', ['ROLE_USER'], 'Jean', 'jean');
        $this->playedEverything($user);

        $byGame = self::getContainer()->get(PlayerGameStatsQueryInterface::class);
        self::assertInstanceOf(PlayerGameStatsQueryInterface::class, $byGame);
        $totals = self::getContainer()->get(PlayerStatsQueryInterface::class);
        self::assertInstanceOf(PlayerStatsQueryInterface::class, $totals);

        $rows = $byGame->perGameForUser($user->getId());
        $profile = $totals->computeForUser($user->getId());

        self::assertSame($profile['goal_completions'], array_sum(array_column($rows, 'goals')));
        self::assertSame($profile['total_checks_done'], array_sum(array_column($rows, 'checks')));
        self::assertSame(2, $profile['runs_participated']);
        // The event run holds both of his games: it counts once in each.
        self::assertSame(3, array_sum(array_column($rows, 'runs')));
    }

    public function testEveryCatalogFactHasAGroup(): void
    {
        self::assertSame([], MemberStatsLayout::otherFacts());
        self::assertCount(4, MemberStatsLayout::superlativeFacts());
    }

    /**
     * An event run with two games (one goal, one released slot without goal kept out), a private run without goal
     * (kept out of the counts), a completed weekly attempt, an hour connected and a DeathLink death.
     */
    private function playedEverything(User $user): void
    {
        $now = new \DateTimeImmutable(self::NOW);
        $event = $this->createEvent('ArchiLAN', $now, $now->modify('+2 days'));
        $hollow = $this->createGame('Hollow Knight', 'hollow-knight');
        $luigi = $this->createGame("Luigi's Mansion", 'luigis-mansion');
        $registration = $this->createRegistration($event->getId(), $user->getId());

        $session = $this->finishedSession($event->getId(), $now);
        $beaten = SessionSlot::create(bin2hex(random_bytes(16)), $session->getId(), $registration->getId(), $hollow->getId(), 'Jean', 0);
        $beaten->recordGoal($now->modify('+1 hour'));
        $beaten->recordProgress(50, 30);
        $released = SessionSlot::create(bin2hex(random_bytes(16)), $session->getId(), $registration->getId(), $hollow->getId(), 'Jean2', 1);
        $released->recordProgress(10, 5);
        $released->markAsReleased();
        $other = SessionSlot::create(bin2hex(random_bytes(16)), $session->getId(), $registration->getId(), $luigi->getId(), 'Jean3', 2);
        $other->recordProgress(20, 10);
        foreach ([$beaten, $released, $other] as $slot) {
            $this->entityManager->persist($slot);
        }

        $span = SlotPlaySpan::open(bin2hex(random_bytes(16)), $session->getId(), 'Jean', $now);
        $span->extendTo($now->modify('+1 hour'));
        $this->entityManager->persist($span);
        $this->entityManager->persist(new SessionFeedEvent(bin2hex(random_bytes(16)), $session->getId(), SessionFeedEvent::TYPE_DEATH, 'Spikes', $now, null, null, null, null, null, null, 'Jean', null, null, null, null, ['Bob']));

        $run = Run::create($user->getId(), 'Sans objectif', $now);
        $private = $this->finishedSession($run->getId(), $now);
        $run->attachSession($private->getId());
        $this->entityManager->persist($run);
        $unfinished = SessionSlot::create(bin2hex(random_bytes(16)), $private->getId(), $user->getId(), $hollow->getId(), 'Jean', 0);
        $unfinished->recordProgress(99, 99);
        $this->entityManager->persist($unfinished);

        $template = new WeeklyTemplate(bin2hex(random_bytes(16)), $luigi->getId(), "name: ArchiLAN\ngame: Archipelago\n", 'Run hebdo', null, true, $now, $now);
        $weekly = new WeeklyRun(bin2hex(random_bytes(16)), $template->getId(), 2026, 40, 'seed-2026-40', WeeklyRun::STATUS_ACTIVE, $now, $now);
        $entry = new WeeklyEntry(bin2hex(random_bytes(16)), $weekly->getId(), $user->getId(), 1, $now, $now, 'ext-session-jean');
        $entry->recordGoal($now->modify('+30 minutes'), 1800, 846, 200);
        foreach ([$template, $weekly, $entry] as $row) {
            $this->entityManager->persist($row);
        }

        $this->entityManager->flush();
    }

    private function finishedSession(string $eventId, \DateTimeImmutable $now): Session
    {
        $session = Session::create(bin2hex(random_bytes(16)), $eventId, $now);
        foreach ([Session::STATUS_VALIDATING, Session::STATUS_READY, Session::STATUS_GENERATING, Session::STATUS_GENERATED, Session::STATUS_LAUNCHING] as $status) {
            $session->transition($status, $now);
        }
        $session->transition(Session::STATUS_RUNNING, $now, 'bridge.local', 38281, 'secret', 5000);
        $session->transition(Session::STATUS_FINISHED, $now->modify('+2 hours'));
        $this->entityManager->persist($session);
        $this->entityManager->flush();

        return $session;
    }

    private function recompute(): RecomputeAchievements
    {
        $recompute = self::getContainer()->get(RecomputeAchievements::class);
        self::assertInstanceOf(RecomputeAchievements::class, $recompute);

        return $recompute;
    }
}
