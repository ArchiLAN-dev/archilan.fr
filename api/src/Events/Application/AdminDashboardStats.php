<?php

declare(strict_types=1);

namespace App\Events\Application;

use App\Events\Domain\Event;
use App\GameSelection\Domain\ArchipelagoGame;
use App\Registrations\Domain\Registration;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final readonly class AdminDashboardStats
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{publishedEvents: int, totalConfirmedRegistrations: int, gameCount: int}
     */
    public function getStats(): array
    {
        $publishedEvents = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(e.id)')
            ->from(Event::class, 'e')
            ->where('e.status IN (:statuses)')
            ->setParameter('statuses', Event::PUBLIC_STATUSES)
            ->getQuery()
            ->getSingleScalarResult();

        $totalConfirmedRegistrations = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(r.id)')
            ->from(Registration::class, 'r')
            ->where('r.status = :status')
            ->setParameter('status', Registration::STATUS_RESERVED)
            ->getQuery()
            ->getSingleScalarResult();

        // TODO: Expand when game library is fully managed via admin CRUD
        $gameCount = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(g.id)')
            ->from(ArchipelagoGame::class, 'g')
            ->getQuery()
            ->getSingleScalarResult();

        $this->logger->debug('AdminDashboardStats.getStats', [
            'publishedEvents' => $publishedEvents,
            'totalConfirmedRegistrations' => $totalConfirmedRegistrations,
            'gameCount' => $gameCount,
        ]);

        return [
            'publishedEvents' => $publishedEvents,
            'totalConfirmedRegistrations' => $totalConfirmedRegistrations,
            'gameCount' => $gameCount,
        ];
    }
}
