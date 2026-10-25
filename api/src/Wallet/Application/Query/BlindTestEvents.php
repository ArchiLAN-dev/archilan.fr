<?php

declare(strict_types=1);

namespace App\Wallet\Application\Query;

use App\Events\Domain\Entity\Event;
use App\Events\Domain\Repository\EventRepositoryInterface;
use App\Wallet\Application\Exception\BlindTestSignatureException;
use App\Wallet\Application\Support\BlindTestLink;
use App\Wallet\Domain\Entity\BlindTestSetting;
use App\Wallet\Domain\Repository\BlindTestSettingRepositoryInterface;
use Psr\Clock\ClockInterface;

/**
 * The events the blind test app's console may prepare a game for (story 41.48): published or under way, soonest
 * first, each with whether its blind test is open on the site. Asked by the app in a signed request, so the animator
 * picks the event instead of typing its id.
 */
final readonly class BlindTestEvents
{
    public function __construct(
        private BlindTestLink $link,
        private EventRepositoryInterface $events,
        private BlindTestSettingRepositoryInterface $settings,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return list<array{id: string, title: string, startsAt: string, endsAt: string, blindTestEnabled: bool}>
     *
     * @throws BlindTestSignatureException
     */
    public function list(string $timestamp, string $signature): array
    {
        if (!$this->link->verifyRequest($timestamp, $signature, '', $this->clock->now())) {
            throw new BlindTestSignatureException();
        }
        $events = $this->events->findByStatuses([Event::STATUS_PUBLISHED, Event::STATUS_IN_PROGRESS]);
        usort($events, static fn (Event $a, Event $b): int => $a->getStartsAt() <=> $b->getStartsAt());

        return array_map(function (Event $event): array {
            $setting = $this->settings->find($event->getId());

            return [
                'id' => $event->getId(),
                'title' => $event->getTitle(),
                'startsAt' => $event->getStartsAt()->format(\DATE_ATOM),
                'endsAt' => $event->getEndsAt()->format(\DATE_ATOM),
                'blindTestEnabled' => $setting instanceof BlindTestSetting && $setting->isEnabled(),
            ];
        }, $events);
    }
}
