<?php

declare(strict_types=1);

namespace App\Wallet\Application\Query;

use App\Shared\Application\Exception\ApplicationFailure;
use App\Wallet\Application\Support\BlindTestGate;
use App\Wallet\Application\Support\BlindTestLink;
use Psr\Clock\ClockInterface;

/**
 * Whether a member may enter the blind test of an event now, and where (story 41.45): the app's login page, the same
 * for everyone; the page of the event shows it as a button and a QR code. Every page of an event asks, so « not now »
 * (blind test off or not configured, event not running) is an answer, not a failure.
 */
final readonly class BlindTestEntry
{
    public function __construct(
        private BlindTestGate $gate,
        private BlindTestLink $link,
        private ClockInterface $clock,
    ) {
    }

    /** Null when the member may not play now. */
    public function entryUrl(string $eventId): ?string
    {
        try {
            $this->gate->open($eventId, $this->clock->now());
        } catch (ApplicationFailure) {
            return null;
        }

        return $this->link->entryUrl();
    }
}
