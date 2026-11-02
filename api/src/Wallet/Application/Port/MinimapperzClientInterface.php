<?php

declare(strict_types=1);

namespace App\Wallet\Application\Port;

use App\Wallet\Application\Exception\MinimapperzUnauthorizedException;
use App\Wallet\Application\Exception\MinimapperzUnavailableException;
use App\Wallet\Application\Support\MinimapperzEvent;

/**
 * Minimapperz's organiser API (epic 49), read only, one API key per event. Story 49.1 reads the event itself; the
 * games, players and ranking come with stories 49.3 and 49.4.
 */
interface MinimapperzClientInterface
{
    /**
     * @throws MinimapperzUnauthorizedException when the key does not open this event
     * @throws MinimapperzUnavailableException  when Minimapperz cannot be read
     */
    public function event(string $slug, string $apiKey): MinimapperzEvent;
}
