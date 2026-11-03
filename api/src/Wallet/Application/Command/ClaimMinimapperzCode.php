<?php

declare(strict_types=1);

namespace App\Wallet\Application\Command;

use App\Events\Domain\Entity\Event;
use App\Events\Domain\Repository\EventRepositoryInterface;
use App\Wallet\Application\Query\EventPellesQueryInterface;
use App\Wallet\Application\Support\MinimapperzSlug;
use App\Wallet\Domain\Entity\MinimapperzSetting;
use App\Wallet\Domain\Repository\MinimapperzCodeRepositoryInterface;
use App\Wallet\Domain\Repository\MinimapperzSettingRepositoryInterface;
use Psr\Clock\ClockInterface;

/**
 * A registrant opens the event's page (story 49.2): they get their Minimapperz code - the one they hold, else a free
 * one handed to them now. Asking again gives the same code. « Not now » is an answer, not a failure: the link off,
 * the event over, the member not registered, or no code left all give null.
 */
final readonly class ClaimMinimapperzCode
{
    public function __construct(
        private MinimapperzSettingRepositoryInterface $settings,
        private MinimapperzCodeRepositoryInterface $codes,
        private EventRepositoryInterface $events,
        private EventPellesQueryInterface $registrants,
        private ClockInterface $clock,
    ) {
    }

    public function claim(string $eventId, string $userId): ?MinimapperzCodeClaim
    {
        $setting = $this->settings->find($eventId);
        $slug = $setting?->getSlug();
        if (!$setting instanceof MinimapperzSetting || !$setting->isEnabled() || null === $slug) {
            return null;
        }
        $now = $this->clock->now();
        $event = $this->events->findById($eventId);
        if (!$event instanceof Event || $now >= $event->getEndsAt()) {
            return null;
        }
        if (!in_array($userId, $this->registrants->activeRegistrantIds($eventId), true)) {
            return null;
        }

        $code = $this->codes->assignFreeCode($eventId, $userId, $now);

        return null === $code ? null : new MinimapperzCodeClaim($code, MinimapperzSlug::lanUrl($slug));
    }
}
