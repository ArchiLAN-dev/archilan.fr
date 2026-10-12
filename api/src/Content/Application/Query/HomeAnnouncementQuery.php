<?php

declare(strict_types=1);

namespace App\Content\Application\Query;

use App\Content\Domain\Entity\HomeAnnouncement;
use App\Content\Domain\Repository\HomeAnnouncementRepositoryInterface;
use App\Shared\Application\Support\PublicMediaUrlResolver;
use Psr\Clock\ClockInterface;

/**
 * The home page announcements (story 10.14). Visitors read the running one, or the next one starting within a day:
 * the home page is regenerated every five minutes (ISR), so it learns of an announcement before it starts and the
 * browser opens it on time, comparing the window with its own clock.
 */
final readonly class HomeAnnouncementQuery
{
    private const string LOOKAHEAD = '+1 day';

    public function __construct(
        private HomeAnnouncementRepositoryInterface $announcements,
        private PublicMediaUrlResolver $publicMedia,
        private ClockInterface $clock,
    ) {
    }

    /** @return array{id: string, title: string, body: string, imageUrl: ?string, ctaLabel: string, ctaUrl: string, startsAt: string, endsAt: string}|null */
    public function forVisitors(): ?array
    {
        $now = $this->clock->now();
        $horizon = $now->modify(self::LOOKAHEAD);
        $next = null;
        foreach ($this->announcements->all() as $announcement) {
            if ($announcement->getEndsAt() <= $now || $announcement->getStartsAt() > $horizon) {
                continue;
            }
            if (null === $next || $announcement->getStartsAt() < $next->getStartsAt()) {
                $next = $announcement;
            }
        }

        return null === $next ? null : $this->view($next);
    }

    /** @return list<array{id: string, title: string, body: string, imageUrl: ?string, ctaLabel: string, ctaUrl: string, startsAt: string, endsAt: string, status: string}> */
    public function forAdmin(): array
    {
        $now = $this->clock->now();

        return array_map(
            fn (HomeAnnouncement $announcement): array => [...$this->view($announcement), 'status' => $announcement->statusAt($now)],
            $this->announcements->all(),
        );
    }

    /** @return array{id: string, title: string, body: string, imageUrl: ?string, ctaLabel: string, ctaUrl: string, startsAt: string, endsAt: string} */
    private function view(HomeAnnouncement $announcement): array
    {
        $imageKey = $announcement->getImageKey();

        return [
            'id' => $announcement->getId(),
            'title' => $announcement->getTitle(),
            'body' => $announcement->getBody(),
            'imageUrl' => null === $imageKey ? null : $this->publicMedia->resolve($imageKey),
            'ctaLabel' => $announcement->getCtaLabel(),
            'ctaUrl' => $announcement->getCtaUrl(),
            'startsAt' => $announcement->getStartsAt()->format(\DATE_ATOM),
            'endsAt' => $announcement->getEndsAt()->format(\DATE_ATOM),
        ];
    }
}
