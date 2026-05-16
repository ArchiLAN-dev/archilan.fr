<?php

declare(strict_types=1);

namespace App\Events\Application;

use App\Events\Domain\Event;
use App\GameSelection\Domain\Game;
use App\Registrations\Domain\Registration;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final readonly class AdminDashboardStats
{
    private string $eventTable;
    private string $registrationTable;
    private string $gameTable;

    public function __construct(
        private Connection $connection,
        EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
        $this->eventTable = $em->getClassMetadata(Event::class)->getTableName();
        $this->registrationTable = $em->getClassMetadata(Registration::class)->getTableName();
        $this->gameTable = $em->getClassMetadata(Game::class)->getTableName();
    }

    /**
     * @return array{publishedEvents: int, totalConfirmedRegistrations: int, gameCount: int}
     */
    public function getStats(): array
    {
        $evtQb = $this->connection->createQueryBuilder();
        $placeholders = array_map(
            static fn (string $s): string => $evtQb->createNamedParameter($s),
            Event::PUBLIC_STATUSES,
        );
        $publishedEventsRaw = $evtQb
            ->select('COUNT(e.id)')
            ->from($this->eventTable, 'e')
            ->where($evtQb->expr()->in('e.status', $placeholders))
            ->executeQuery()
            ->fetchOne();
        $publishedEvents = is_numeric($publishedEventsRaw) ? (int) $publishedEventsRaw : 0;

        $regQb = $this->connection->createQueryBuilder();
        $confirmedRaw = $regQb
            ->select('COUNT(r.id)')
            ->from($this->registrationTable, 'r')
            ->where($regQb->expr()->neq('r.status', ':status'))
            ->setParameter('status', Registration::STATUS_CANCELLED)
            ->executeQuery()
            ->fetchOne();
        $totalConfirmedRegistrations = is_numeric($confirmedRaw) ? (int) $confirmedRaw : 0;

        $gameQb = $this->connection->createQueryBuilder();
        $gameCountRaw = $gameQb
            ->select('COUNT(g.id)')
            ->from($this->gameTable, 'g')
            ->executeQuery()
            ->fetchOne();
        $gameCount = is_numeric($gameCountRaw) ? (int) $gameCountRaw : 0;

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
