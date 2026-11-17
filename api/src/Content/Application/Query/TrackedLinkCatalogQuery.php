<?php

declare(strict_types=1);

namespace App\Content\Application\Query;

use App\Content\Domain\Entity\TrackedLink;
use App\Content\Domain\Repository\TrackedLinkRepositoryInterface;
use App\Shared\Application\Exception\NotFoundException;
use Psr\Clock\ClockInterface;

/**
 * The tracked links for the admin (story 55.1), newest first, with their visits (story 55.2). A period counts its
 * last day, today in Paris: the last 7 days are today and the 6 before.
 */
final readonly class TrackedLinkCatalogQuery
{
    private const string TIMEZONE = 'Europe/Paris';
    public const int DETAIL_DAYS = 90;
    private const int MAX_ORIGINS = 10;
    public const array NO_VISITS = ['total' => 0, 'last7' => 0, 'last30' => 0];

    public function __construct(
        private TrackedLinkRepositoryInterface $links,
        private TrackedLinkStatsQueryInterface $stats,
        private ClockInterface $clock,
    ) {
    }

    /** @return list<array{id: string, code: string, label: string, target: string, direction: 'inbound'|'outbound', active: bool, createdAt: string, partnerId: string|null, visits: array{total: int, last7: int, last30: int}}> */
    public function forAdmin(): array
    {
        $totals = $this->visitsByLink();

        return array_map(fn (TrackedLink $link): array => [
            ...$this->view($link),
            'visits' => $totals[$link->getId()] ?? self::NO_VISITS,
        ], $this->links->all());
    }

    /**
     * Story 55.3: the counts of every link that was visited, by link id (a missing link has none).
     *
     * @return array<string, array{total: int, last7: int, last30: int}>
     */
    public function visitsByLink(): array
    {
        $today = $this->today();

        return $this->stats->totals(self::since($today, 7), self::since($today, 30));
    }

    /**
     * One link and its last 90 days: one entry per day, zero included, and the hosts its visitors came from (empty is
     * « direct »).
     *
     * @return array{link: array{id: string, code: string, label: string, target: string, direction: 'inbound'|'outbound', active: bool, createdAt: string, partnerId: string|null}, days: list<array{day: string, visits: int}>, origins: list<array{host: string, visits: int}>}
     *
     * @throws NotFoundException when the link does not exist
     */
    public function detail(string $id): array
    {
        $link = $this->links->find($id);
        if (!$link instanceof TrackedLink) {
            throw new NotFoundException('Lien introuvable.', 'tracked_link_not_found');
        }
        $today = $this->today();
        $since = self::since($today, self::DETAIL_DAYS);
        $counted = $this->stats->daily($id, $since);
        $days = [];
        for ($day = new \DateTimeImmutable($since); $day <= $today; $day = $day->modify('+1 day')) {
            $key = $day->format('Y-m-d');
            $days[] = ['day' => $key, 'visits' => $counted[$key] ?? 0];
        }

        return ['link' => $this->view($link), 'days' => $days, 'origins' => $this->stats->origins($id, $since, self::MAX_ORIGINS)];
    }

    /** @return array{id: string, code: string, label: string, target: string, direction: 'inbound'|'outbound', active: bool, createdAt: string, partnerId: string|null} */
    private function view(TrackedLink $link): array
    {
        return [
            'id' => $link->getId(),
            'code' => $link->getCode(),
            'label' => $link->getLabel(),
            'target' => $link->getTarget(),
            'direction' => $link->isOutbound() ? 'outbound' : 'inbound',
            'active' => $link->isActive(),
            'createdAt' => $link->getCreatedAt()->format(\DateTimeInterface::ATOM),
            // Story 55.3: a partner's link is changed through its partner only.
            'partnerId' => $link->getPartnerId(),
        ];
    }

    /** Today in Paris, at midnight with no time zone attached: days are compared as dates. */
    private function today(): \DateTimeImmutable
    {
        return new \DateTimeImmutable($this->clock->now()->setTimezone(new \DateTimeZone(self::TIMEZONE))->format('Y-m-d'));
    }

    /** The first day of the last `$days` days, today included. */
    private static function since(\DateTimeImmutable $today, int $days): string
    {
        return $today->modify(sprintf('-%d days', $days - 1))->format('Y-m-d');
    }
}
