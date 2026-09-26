<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\GameSelection\Application\Query\ApworldIncidentListQueryInterface;
use App\GameSelection\Application\Query\ApworldIncidentListScope;
use App\GameSelection\Domain\Entity\ApworldIncident;
use App\GameSelection\Domain\Enum\ApworldIncidentType;

/**
 * Story 38.3 review: the history grows forever, the page shows its most recent part.
 */
final class ApworldIncidentHistoryLimitTest extends FunctionalTestCase
{
    public function testTheHistoryStopsAtTheMostRecentClosedIncidentsAndKeepsEveryActiveOne(): void
    {
        $game = $this->createGame('Crystal Project', 'crystal-project');
        $limit = ApworldIncidentListQueryInterface::HISTORY_LIMIT;
        $start = new \DateTimeImmutable('2026-01-01T00:00:00+00:00');
        for ($i = 0; $i <= $limit; ++$i) {
            $at = $start->modify('+'.$i.' hours');
            $closed = ApworldIncident::open(sprintf('closed%026d', $i), $game->getId(), 'hash-'.$i, ApworldIncidentType::PreflightFailed, 'boom', $at);
            $closed->resolve($at, null);
            $this->entityManager->persist($closed);
        }
        $this->entityManager->persist(ApworldIncident::open('active00000000000000000000000001', $game->getId(), 'hash-live', ApworldIncidentType::PreflightFailed, 'boom', $start));
        $this->entityManager->flush();

        $query = self::getContainer()->get(ApworldIncidentListQueryInterface::class);
        self::assertInstanceOf(ApworldIncidentListQueryInterface::class, $query);

        $closed = $query->list(ApworldIncidentListScope::Closed, null);
        self::assertCount($limit, $closed);
        self::assertNotContains('closed00000000000000000000000000', array_map(static fn ($item): string => $item->id, $closed), 'the oldest one is left out');

        $all = $query->list(ApworldIncidentListScope::All, null);
        self::assertCount($limit + 1, $all);
        self::assertSame('active00000000000000000000000001', $all[0]->id);
    }
}
