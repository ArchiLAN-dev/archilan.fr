<?php

declare(strict_types=1);

namespace App\Events\Application;

use App\Events\Domain\Event;
use App\GameSelection\Domain\Game;
use App\Identity\Domain\User;
use App\Membership\Domain\Membership;
use App\Payments\Domain\HelloAssoOrder;
use App\Registrations\Domain\Registration;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final readonly class AdminDashboardStats
{
    private string $eventTable;
    private string $registrationTable;
    private string $gameTable;
    private string $userTable;
    private string $membershipTable;
    private string $helloassoOrderTable;

    public function __construct(
        private Connection $connection,
        EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
        $this->eventTable = $em->getClassMetadata(Event::class)->getTableName();
        $this->registrationTable = $em->getClassMetadata(Registration::class)->getTableName();
        $this->gameTable = $em->getClassMetadata(Game::class)->getTableName();
        $this->userTable = $connection->quoteSingleIdentifier($em->getClassMetadata(User::class)->getTableName());
        $this->membershipTable = $em->getClassMetadata(Membership::class)->getTableName();
        $this->helloassoOrderTable = $em->getClassMetadata(HelloAssoOrder::class)->getTableName();
    }

    /**
     * @return array{publishedEvents: int, totalActiveRegistrations: int, gameCount: int, userCount: int, activeMemberCount: int, totalRevenueCents: int}
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
        $activeRegRaw = $regQb
            ->select('COUNT(r.id)')
            ->from($this->registrationTable, 'r')
            ->where($regQb->expr()->neq('r.status', ':status'))
            ->setParameter('status', Registration::STATUS_CANCELLED)
            ->executeQuery()
            ->fetchOne();
        $totalActiveRegistrations = is_numeric($activeRegRaw) ? (int) $activeRegRaw : 0;

        $gameQb = $this->connection->createQueryBuilder();
        $gameCountRaw = $gameQb
            ->select('COUNT(g.id)')
            ->from($this->gameTable, 'g')
            ->executeQuery()
            ->fetchOne();
        $gameCount = is_numeric($gameCountRaw) ? (int) $gameCountRaw : 0;

        $userQb = $this->connection->createQueryBuilder();
        $userCountRaw = $userQb
            ->select('COUNT(u.id)')
            ->from($this->userTable, 'u')
            ->executeQuery()
            ->fetchOne();
        $userCount = is_numeric($userCountRaw) ? (int) $userCountRaw : 0;

        $now = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $memberQb = $this->connection->createQueryBuilder();
        $activeMemberRaw = $memberQb
            ->select('COUNT(m.id)')
            ->from($this->membershipTable, 'm')
            ->where($memberQb->expr()->eq('m.status', ':status'))
            ->andWhere($memberQb->expr()->gte('m.expires_at', ':now'))
            ->setParameter('status', 'active')
            ->setParameter('now', $now)
            ->executeQuery()
            ->fetchOne();
        $activeMemberCount = is_numeric($activeMemberRaw) ? (int) $activeMemberRaw : 0;

        $revenueQb = $this->connection->createQueryBuilder();
        $revenueRaw = $revenueQb
            ->select('SUM(o.amount_cents)')
            ->from($this->helloassoOrderTable, 'o')
            ->where($revenueQb->expr()->isNotNull('o.paid_at'))
            ->executeQuery()
            ->fetchOne();
        $totalRevenueCents = is_numeric($revenueRaw) ? (int) $revenueRaw : 0;

        $this->logger->debug('AdminDashboardStats.getStats', [
            'publishedEvents' => $publishedEvents,
            'totalActiveRegistrations' => $totalActiveRegistrations,
            'gameCount' => $gameCount,
            'userCount' => $userCount,
            'activeMemberCount' => $activeMemberCount,
            'totalRevenueCents' => $totalRevenueCents,
        ]);

        return [
            'publishedEvents' => $publishedEvents,
            'totalActiveRegistrations' => $totalActiveRegistrations,
            'gameCount' => $gameCount,
            'userCount' => $userCount,
            'activeMemberCount' => $activeMemberCount,
            'totalRevenueCents' => $totalRevenueCents,
        ];
    }
}
