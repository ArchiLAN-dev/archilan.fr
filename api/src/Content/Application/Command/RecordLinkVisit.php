<?php

declare(strict_types=1);

namespace App\Content\Application\Command;

use App\Content\Domain\Entity\TrackedLink;
use App\Content\Domain\Repository\TrackedLinkRepositoryInterface;
use App\Shared\Application\Exception\NotFoundException;
use Psr\Clock\ClockInterface;

/**
 * A pass through `/l/{code}` (story 55.1): counts it on the day in Paris, with the host the visitor came from, and
 * gives the target. The site decides what counts (no robot, no prefetch): `count` false only reads the target.
 * Nothing about the visitor is kept but that host.
 */
final readonly class RecordLinkVisit
{
    private const string TIMEZONE = 'Europe/Paris';
    private const int MAX_HOST = 253;

    public function __construct(
        private TrackedLinkRepositoryInterface $links,
        private ClockInterface $clock,
    ) {
    }

    /** @throws NotFoundException when no active link has that code */
    public function visit(string $code, string $referrerHost, bool $count): LinkVisitTarget
    {
        $link = TrackedLink::isValidCode($code) ? $this->links->findByCode($code) : null;
        if (!$link instanceof TrackedLink || !$link->isActive()) {
            throw new NotFoundException('Lien introuvable.', 'tracked_link_not_found');
        }
        if ($count) {
            $day = $this->clock->now()->setTimezone(new \DateTimeZone(self::TIMEZONE))->format('Y-m-d');
            $this->links->countVisit($link->getId(), $day, self::host($referrerHost));
        }

        return new LinkVisitTarget($link->getTarget());
    }

    /** `www.Discord.com` gives `discord.com`; anything that is not a plain host name gives empty (« direct »). */
    public static function host(string $raw): string
    {
        $host = strtolower(trim($raw));
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }
        if ('' === $host || strlen($host) > self::MAX_HOST || 1 !== preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/', $host)) {
            return '';
        }

        return $host;
    }
}
