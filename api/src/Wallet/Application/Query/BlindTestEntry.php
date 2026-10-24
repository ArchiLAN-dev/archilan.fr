<?php

declare(strict_types=1);

namespace App\Wallet\Application\Query;

use App\Wallet\Application\Support\BlindTestGate;
use App\Wallet\Application\Support\BlindTestLink;
use Psr\Clock\ClockInterface;

/**
 * Whether a member may enter the blind test of an event now, and where (story 41.45): the app's login page, the same
 * for everyone; the page of the event shows it as a button and a QR code.
 */
final readonly class BlindTestEntry
{
    public function __construct(
        private BlindTestGate $gate,
        private BlindTestLink $link,
        private ClockInterface $clock,
    ) {
    }

    /** @throws \App\Shared\Application\Exception\ApplicationFailure when the member may not play now */
    public function entryUrl(string $userId, string $eventId): string
    {
        $this->gate->open($eventId, $userId, $this->clock->now());

        return $this->link->entryUrl();
    }
}
