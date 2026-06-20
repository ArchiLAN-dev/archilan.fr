<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\PersonalRuns\Domain\Run;
use App\PersonalRuns\Domain\RunParticipant;

final class PersonalRunRecentlyPlayedTest extends FunctionalTestCase
{
    public function testRecentlyPlayedGamesAreDedupedNewestFirstAndScopedToUser(): void
    {
        $alice = $this->createUser('alice@example.org');
        $bob = $this->createUser('bob@example.org');

        $zelda = $this->createGame('Zelda', 'zelda');
        $metroid = $this->createGame('Metroid', 'metroid');
        $celeste = $this->createGame('Celeste', 'celeste');
        $hollow = $this->createGame('Hollow Knight', 'hollow-knight');

        // Newest launched run: zelda + metroid.
        $this->createPlayedRun($alice->getId(), 'Run A', Run::STATUS_COMPLETED, '2026-05-12T12:00:00+00:00', [$zelda->getId(), $metroid->getId()]);
        // Older launched run: celeste + zelda (zelda must keep its newer Run A timestamp/title).
        $this->createPlayedRun($alice->getId(), 'Run B', Run::STATUS_IDLE, '2026-05-10T12:00:00+00:00', [$celeste->getId(), $zelda->getId()]);
        // Draft run: never launched -> excluded.
        $this->createPlayedRun($alice->getId(), 'Run C draft', Run::STATUS_DRAFT, '2026-05-13T12:00:00+00:00', [$hollow->getId()]);
        // Another member's launched run -> never leaks into alice's history.
        $this->createPlayedRun($bob->getId(), 'Bob run', Run::STATUS_COMPLETED, '2026-05-14T12:00:00+00:00', [$hollow->getId()]);

        // The run alice is currently editing (no slots of its own).
        $current = $this->createPlayedRun($alice->getId(), 'Current', Run::STATUS_DRAFT, '2026-05-09T12:00:00+00:00', []);

        $this->loginAs($alice);
        $this->client->jsonRequest('GET', '/api/v1/runs/'.$current->getId().'/participants/me/game-selection');

        self::assertResponseIsSuccessful();
        $recent = $this->recentlyPlayedFromResponse();
        self::assertCount(3, $recent);

        $gameIds = $this->gameIdsOf($recent);
        self::assertSame([$zelda->getId(), $metroid->getId(), $celeste->getId()], $gameIds, 'deduped, newest play first, capped at 3');
        self::assertNotContains($hollow->getId(), $gameIds, 'draft runs and other members never contribute');

        // zelda carries its most recent play (Run A), not Run B.
        $first = $recent[0];
        self::assertIsArray($first);
        self::assertSame('Run A', $first['runTitle']);
        self::assertIsString($first['lastPlayedAt']);
        self::assertStringStartsWith('2026-05-12', $first['lastPlayedAt']);
    }

    public function testCurrentRunIsExcludedFromItsOwnRecentlyPlayed(): void
    {
        $alice = $this->createUser('alice@example.org');
        $zelda = $this->createGame('Zelda', 'zelda');
        $metroid = $this->createGame('Metroid', 'metroid');
        $celeste = $this->createGame('Celeste', 'celeste');

        $runA = $this->createPlayedRun($alice->getId(), 'Run A', Run::STATUS_COMPLETED, '2026-05-12T12:00:00+00:00', [$zelda->getId(), $metroid->getId()]);
        $this->createPlayedRun($alice->getId(), 'Run B', Run::STATUS_IDLE, '2026-05-10T12:00:00+00:00', [$celeste->getId(), $zelda->getId()]);

        $this->loginAs($alice);
        $this->client->jsonRequest('GET', '/api/v1/runs/'.$runA->getId().'/participants/me/game-selection');

        self::assertResponseIsSuccessful();
        $recent = $this->recentlyPlayedFromResponse();

        $gameIds = $this->gameIdsOf($recent);
        self::assertNotContains($metroid->getId(), $gameIds, 'metroid only exists in the current run -> excluded');
        self::assertContains($celeste->getId(), $gameIds, 'games from other launched runs remain');
        self::assertContains($zelda->getId(), $gameIds, 'zelda survives via Run B even though Run A is excluded');
    }

    public function testNoHistoryReturnsEmptyList(): void
    {
        $alice = $this->createUser('alice@example.org');
        $current = $this->createPlayedRun($alice->getId(), 'Current', Run::STATUS_DRAFT, '2026-05-09T12:00:00+00:00', []);

        $this->loginAs($alice);
        $this->client->jsonRequest('GET', '/api/v1/runs/'.$current->getId().'/participants/me/game-selection');

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->recentlyPlayedFromResponse());
    }

    /**
     * @return array<mixed>
     */
    private function recentlyPlayedFromResponse(): array
    {
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);
        $recent = $data['recentlyPlayedGames'] ?? null;
        self::assertIsArray($recent);

        return $recent;
    }

    /**
     * @param array<mixed> $recent
     *
     * @return list<mixed>
     */
    private function gameIdsOf(array $recent): array
    {
        $gameIds = [];
        foreach ($recent as $entry) {
            self::assertIsArray($entry);
            $gameIds[] = $entry['gameId'] ?? null;
        }

        return $gameIds;
    }

    /**
     * @param list<string> $gameIds
     */
    private function createPlayedRun(string $ownerId, string $title, string $status, string $updatedAt, array $gameIds): Run
    {
        $now = new \DateTimeImmutable($updatedAt);
        $run = new Run(
            bin2hex(random_bytes(16)),
            $ownerId,
            $title,
            $status,
            bin2hex(random_bytes(32)),
            null,
            $now,
            $now,
        );
        $this->entityManager->persist($run);

        if ([] !== $gameIds) {
            $participant = RunParticipant::create($run->getId(), $ownerId, $now);
            $participant->replaceSlots(array_map(
                static fn (string $gameId): array => ['slotId' => bin2hex(random_bytes(8)), 'gameId' => $gameId],
                $gameIds,
            ));
            $this->entityManager->persist($participant);
        }

        $this->entityManager->flush();

        return $run;
    }
}
