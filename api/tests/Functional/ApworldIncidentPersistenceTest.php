<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\GameSelection\Application\Query\ServedApworld;
use App\GameSelection\Application\Query\ServedApworldsQueryInterface;
use App\GameSelection\Domain\Entity\ApworldIncident;
use App\GameSelection\Domain\Enum\ApworldIncidentStatus;
use App\GameSelection\Domain\Enum\ApworldIncidentType;
use App\GameSelection\Domain\Repository\ApworldIncidentRepositoryInterface;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * Story 38.1: the incident store against the real schema - the partial unique index that forbids a
 * second active incident for a key, and the reads the reconciliation relies on.
 */
final class ApworldIncidentPersistenceTest extends FunctionalTestCase
{
    private ApworldIncidentRepositoryInterface $incidents;

    protected function setUp(): void
    {
        parent::setUp();

        $incidents = self::getContainer()->get(ApworldIncidentRepositoryInterface::class);
        self::assertInstanceOf(ApworldIncidentRepositoryInterface::class, $incidents);
        $this->incidents = $incidents;
    }

    public function testAnIncidentRoundTripsWithItsTransitions(): void
    {
        $incident = $this->incident('incident-a', '2026-09-24 10:00:00+00:00');
        $incident->acknowledge('admin-1', new \DateTimeImmutable('2026-09-24 11:00:00+00:00'));
        $this->incidents->save($incident);
        $this->incidents->flush();
        $this->entityManager->clear();

        $reloaded = $this->incidents->findById('incident-a');

        self::assertInstanceOf(ApworldIncident::class, $reloaded);
        self::assertSame(ApworldIncidentType::PreflightFailed, $reloaded->getType());
        self::assertSame(ApworldIncidentStatus::Acknowledged, $reloaded->getStatus());
        self::assertSame('admin-1', $reloaded->getAcknowledgedBy());
        self::assertEquals(new \DateTimeImmutable('2026-09-24 10:00:00+00:00'), $reloaded->getOpenedAt());
    }

    public function testFindActiveIgnoresClosedIncidents(): void
    {
        $closed = $this->incident('incident-closed', '2026-09-20 10:00:00+00:00');
        $closed->resolve(new \DateTimeImmutable('2026-09-21 10:00:00+00:00'), null);
        $this->incidents->save($closed);
        $this->incidents->save($this->incident('incident-active', '2026-09-24 10:00:00+00:00'));
        $this->incidents->flush();

        $active = $this->incidents->findActive('game-1', 'hash-1', ApworldIncidentType::PreflightFailed);

        self::assertSame('incident-active', $active?->getId());
        self::assertNull($this->incidents->findActive('game-1', 'other-hash', ApworldIncidentType::PreflightFailed));
        self::assertSame(['incident-active'], array_map(
            static fn (ApworldIncident $i): string => $i->getId(),
            $this->incidents->findAllActive(),
        ));
    }

    public function testUniqueIndexRejectsASecondActiveIncidentForTheSameKey(): void
    {
        $this->incidents->save($this->incident('incident-1', '2026-09-24 10:00:00+00:00'));
        $this->incidents->flush();

        $this->expectException(UniqueConstraintViolationException::class);

        $this->incidents->save($this->incident('incident-2', '2026-09-24 10:05:00+00:00'));
        $this->incidents->flush();
    }

    public function testClosedIncidentsDoNotBlockANewActiveOne(): void
    {
        $first = $this->incident('incident-1', '2026-09-20 10:00:00+00:00');
        $first->resolve(new \DateTimeImmutable('2026-09-21 10:00:00+00:00'), null);
        $this->incidents->save($first);
        $this->incidents->flush();

        $this->incidents->save($this->incident('incident-2', '2026-09-24 10:00:00+00:00'));
        $this->incidents->flush();

        self::assertSame('incident-2', $this->incidents->findActive('game-1', 'hash-1', ApworldIncidentType::PreflightFailed)?->getId());
    }

    public function testFindLatestClosedReturnsTheMostRecent(): void
    {
        $older = $this->incident('incident-older', '2026-09-10 10:00:00+00:00');
        $older->resolve(new \DateTimeImmutable('2026-09-11 10:00:00+00:00'), null);
        $newer = $this->incident('incident-newer', '2026-09-20 10:00:00+00:00');
        $newer->ignore(new \DateTimeImmutable('2026-09-21 10:00:00+00:00'), 'admin-1');
        $this->incidents->save($older);
        $this->incidents->save($newer);
        $this->incidents->flush();

        $latest = $this->incidents->findLatestClosed('game-1', 'hash-1', ApworldIncidentType::PreflightFailed);

        self::assertSame('incident-newer', $latest?->getId());
    }

    public function testServedApworldsListsOnlyGamesWithAnApworld(): void
    {
        $served = $this->createGame('Crystal Project', 'crystal-project');
        $served->configureApworld('key.apworld', 'hash-crystal', 'Crystal Project', "game: Crystal Project\n", new \DateTimeImmutable());
        $this->createGame('No Apworld', 'no-apworld');
        $this->entityManager->flush();

        $query = self::getContainer()->get(ServedApworldsQueryInterface::class);
        self::assertInstanceOf(ServedApworldsQueryInterface::class, $query);

        self::assertEquals(
            [new ServedApworld($served->getId(), 'hash-crystal')],
            $query->servedApworlds(),
        );
    }

    public function testServedApworldsSayWhichGameIsDisabled(): void
    {
        // Story 38.9: the rolling test leaves a disabled game alone.
        $disabled = $this->createGame('Old Game', 'old-game');
        $disabled->configureApworld('key.apworld', 'hash-old', 'Old Game', "game: Old Game\n", new \DateTimeImmutable());
        $disabled->disable('Retiré du catalogue.', new \DateTimeImmutable());
        $this->entityManager->flush();

        $query = self::getContainer()->get(ServedApworldsQueryInterface::class);
        self::assertInstanceOf(ServedApworldsQueryInterface::class, $query);

        self::assertEquals([new ServedApworld($disabled->getId(), 'hash-old', disabled: true)], $query->servedApworlds());
    }

    private function incident(string $id, string $openedAt): ApworldIncident
    {
        return ApworldIncident::open(
            $id,
            'game-1',
            'hash-1',
            ApworldIncidentType::PreflightFailed,
            'FillError: boom',
            new \DateTimeImmutable($openedAt),
        );
    }
}
