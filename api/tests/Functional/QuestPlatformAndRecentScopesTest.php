<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\GameSelection\Domain\Entity\ApworldCandidate;
use App\GameSelection\Domain\Entity\Game;
use App\GameSelection\Domain\Entity\GameCatalogSync;
use App\GameSelection\Domain\Enum\ApworldCandidateOrigin;
use App\Identity\Domain\Entity\User;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Entity\SessionFeedEvent;
use App\Sessions\Domain\Entity\SessionSlot;
use App\Wallet\Application\Query\WeeklyQuestsQueryInterface;
use App\Wallet\Domain\Enum\QuestMetric;
use App\Wallet\Domain\ValueObject\QuestObjective;
use App\Wallet\Domain\ValueObject\QuestWeek;

/**
 * Story 41.66: an objective aimed at some platform families, or at the games put online lately.
 */
final class QuestPlatformAndRecentScopesTest extends FunctionalTestCase
{
    // Week 2026-W40: Monday 2026-09-28 00:00 Paris (2026-09-27T22:00Z) to Monday 2026-10-05.
    private const string IN_THE_WEEK = '2026-10-01T12:00:00+00:00';

    public function testAPlatformTargetCountsTheGamesOfItsFamiliesAsTheCatalogResolvesThem(): void
    {
        // Families from IGDB, from the admin's override, and a game outside the selection.
        $snes = $this->gameOnIgdb('Chrono Trigger', 'chrono', ['Super Famicom']);
        $n64 = $this->createGame('Ocarina', 'ocarina');
        $n64->overridePlatformFamilies(['Nintendo 64'], new \DateTimeImmutable('2026-05-02T10:00:00+00:00'));
        $pc = $this->gameOnIgdb('Hollow Knight', 'hk', ['PC (Microsoft Windows)']);
        $alice = $this->createUser('alice@example.org', ['ROLE_USER'], 'Alice');
        $bob = $this->createUser('bob@example.org', ['ROLE_USER'], 'Bob');
        $this->played('s-1', $alice, $snes);
        $this->played('s-2', $alice, $n64);
        $this->played('s-3', $bob, $pc);
        $this->entityManager->flush();

        $nintendo = new QuestObjective(QuestMetric::Checks, 1, QuestObjective::SCOPE_PLATFORM, 'Super Nintendo|Nintendo 64');
        $nowhere = new QuestObjective(QuestMetric::Checks, 1, QuestObjective::SCOPE_PLATFORM, 'Dreamcast');
        $counts = $this->counts([$nintendo, $nowhere]);

        self::assertSame([$alice->getId() => 2], $counts[$nintendo->key()]);
        self::assertSame([], $counts[$nowhere->key()], 'no game of that family');
        self::assertContains('Super Nintendo', $this->query()->scopeOptions()['platforms']);
        self::assertContains('Nintendo 64', $this->query()->scopeOptions()['platforms']);
    }

    public function testARecentTargetCountsTheGamesPutOnlineFromNDaysBeforeTheWeek(): void
    {
        // Promoted ten days before the week; promoted three months before; created this week with no candidate yet;
        // created in May with no candidate (older than the candidates' history).
        $fresh = $this->createGame('Fresh', 'fresh');
        $this->promoted($fresh, '2026-09-17T10:00:00+00:00');
        $old = $this->createGame('Old', 'old');
        $this->promoted($old, '2026-07-01T10:00:00+00:00');
        $brandNew = $this->createGame('Brand new', 'brand-new');
        $this->entityManager->getConnection()->executeStatement('UPDATE game SET created_at = :at WHERE id = :id', ['at' => '2026-09-30T10:00:00+00:00', 'id' => $brandNew->getId()]);
        $may = $this->createGame('May', 'may');
        $alice = $this->createUser('alice@example.org', ['ROLE_USER'], 'Alice');
        foreach ([$fresh, $old, $brandNew, $may] as $index => $game) {
            $this->played('s-'.$index, $alice, $game);
        }
        $this->entityManager->flush();

        $recent = new QuestObjective(QuestMetric::Sessions, 1, QuestObjective::SCOPE_RECENT, '30');

        self::assertSame([$alice->getId() => 2], $this->counts([$recent])[$recent->key()], 'the fresh promotion and the game created this week');
    }

    /**
     * @param list<QuestObjective> $objectives
     *
     * @return array<string, array<string, int>>
     */
    private function counts(array $objectives): array
    {
        return $this->query()->counts(QuestWeek::containing(new \DateTimeImmutable(self::IN_THE_WEEK)), $objectives);
    }

    private function query(): WeeklyQuestsQueryInterface
    {
        $query = self::getContainer()->get(WeeklyQuestsQueryInterface::class);
        self::assertInstanceOf(WeeklyQuestsQueryInterface::class, $query);

        return $query;
    }

    /**
     * @param list<string> $platforms IGDB platform names
     */
    private function gameOnIgdb(string $name, string $slug, array $platforms): Game
    {
        $game = $this->createGame($name, $slug);
        $sync = new GameCatalogSync($game);
        $game->attachCatalogSync($sync);
        $game->recordPlatforms(array_map(static fn (string $platform, int $id): array => ['id' => $id + 1, 'name' => $platform], $platforms, array_keys($platforms)));
        $this->entityManager->persist($sync);

        return $game;
    }

    private function promoted(Game $game, string $at): void
    {
        $candidate = ApworldCandidate::submit(bin2hex(random_bytes(8)), $game->getId(), 'hash-'.$game->getId(), 'k.apworld', 'k.apworld', "y\n", $game->getName(), null, ApworldCandidateOrigin::Manual, null, new \DateTimeImmutable($at));
        $candidate->promote(new \DateTimeImmutable($at), null);
        $this->entityManager->persist($candidate);
    }

    private function played(string $sessionId, User $user, Game $game): void
    {
        $this->entityManager->persist(Session::create($sessionId, 'event-'.$sessionId, new \DateTimeImmutable(self::IN_THE_WEEK)));
        $this->entityManager->persist(SessionSlot::create(bin2hex(random_bytes(16)), $sessionId, $user->getId(), $game->getId(), 'P-'.$sessionId, 1, 'slot-'.$sessionId));
        $this->entityManager->persist(new SessionFeedEvent(
            bin2hex(random_bytes(16)), $sessionId, SessionFeedEvent::TYPE_ITEM_RECEIVED, 'check', new \DateTimeImmutable(self::IN_THE_WEEK),
            1, 'Item', 0, 2, 'Location', 1, 'P-'.$sessionId, 'Game', 2, 'Someone', 'Game',
        ));
    }
}
