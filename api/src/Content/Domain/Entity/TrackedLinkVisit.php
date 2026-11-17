<?php

declare(strict_types=1);

namespace App\Content\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * The visits of a tracked link on one day (Paris), from one host (story 55.1); an empty host is « direct ». Mapped so
 * the schema knows the table, but only ever written by `TrackedLinkRepositoryInterface::countVisit` (one upsert, so two
 * visits at once both count) and read by queries.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'tracked_link_visit')]
final class TrackedLinkVisit
{
    private function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'link_id', type: 'string', length: 32)]
        private string $linkId,
        #[ORM\Id]
        #[ORM\Column(type: 'date_immutable')]
        private \DateTimeImmutable $day,
        #[ORM\Id]
        #[ORM\Column(name: 'referrer_host', type: 'string', length: 253)]
        private string $referrerHost,
        #[ORM\Column(type: 'integer')]
        private int $visits,
    ) {
    }

    public function getLinkId(): string
    {
        return $this->linkId;
    }

    public function getDay(): \DateTimeImmutable
    {
        return $this->day;
    }

    public function getReferrerHost(): string
    {
        return $this->referrerHost;
    }

    public function getVisits(): int
    {
        return $this->visits;
    }
}
