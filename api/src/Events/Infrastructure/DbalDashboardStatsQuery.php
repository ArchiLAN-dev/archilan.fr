<?php

declare(strict_types=1);

namespace App\Events\Infrastructure;

use App\Events\Application\DashboardStatsQueryInterface;
use App\Events\Domain\Event;
use App\Registrations\Domain\Registration;
use Doctrine\DBAL\Connection;

final readonly class DbalDashboardStatsQuery implements DashboardStatsQueryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function getStats(): array
    {
        $evtQb = $this->connection->createQueryBuilder();
        $placeholders = array_map(
            static fn (string $s): string => $evtQb->createNamedParameter($s),
            Event::PUBLIC_STATUSES,
        );
        $publishedEventsRaw = $evtQb
            ->select('COUNT(e.id)')
            ->from('event', 'e')
            ->where($evtQb->expr()->in('e.status', $placeholders))
            ->executeQuery()
            ->fetchOne();
        $publishedEvents = is_numeric($publishedEventsRaw) ? (int) $publishedEventsRaw : 0;

        $regQb = $this->connection->createQueryBuilder();
        $activeRegRaw = $regQb
            ->select('COUNT(r.id)')
            ->from('registration', 'r')
            ->where($regQb->expr()->neq('r.status', ':status'))
            ->setParameter('status', Registration::STATUS_CANCELLED)
            ->executeQuery()
            ->fetchOne();
        $totalActiveRegistrations = is_numeric($activeRegRaw) ? (int) $activeRegRaw : 0;

        $gameQb = $this->connection->createQueryBuilder();
        $gameCountRaw = $gameQb
            ->select('COUNT(g.id)')
            ->from('game', 'g')
            ->executeQuery()
            ->fetchOne();
        $gameCount = is_numeric($gameCountRaw) ? (int) $gameCountRaw : 0;

        $userQb = $this->connection->createQueryBuilder();
        $userTable = $this->connection->quoteSingleIdentifier('user');
        $userCountRaw = $userQb
            ->select('COUNT(u.id)')
            ->from($userTable, 'u')
            ->executeQuery()
            ->fetchOne();
        $userCount = is_numeric($userCountRaw) ? (int) $userCountRaw : 0;

        $now = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $memberQb = $this->connection->createQueryBuilder();
        $activeMemberRaw = $memberQb
            ->select('COUNT(m.id)')
            ->from('memberships', 'm')
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
            ->from('hello_asso_order', 'o')
            ->where($revenueQb->expr()->isNotNull('o.paid_at'))
            ->executeQuery()
            ->fetchOne();
        $totalRevenueCents = is_numeric($revenueRaw) ? (int) $revenueRaw : 0;

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
