<?php

declare(strict_types=1);

namespace App\Wallet\Application\Port;

use App\Wallet\Application\Exception\MinimapperzUnauthorizedException;
use App\Wallet\Application\Exception\MinimapperzUnavailableException;
use App\Wallet\Application\Support\MinimapperzEvent;
use App\Wallet\Application\Support\MinimapperzGame;
use App\Wallet\Application\Support\MinimapperzPlayer;
use App\Wallet\Application\Support\MinimapperzRankingEntry;

/**
 * Minimapperz's organiser API (epic 49), read only, one API key per event. Story 49.1 reads the event itself, story
 * 49.3 its finished games and its players, story 49.4 its ranking.
 */
interface MinimapperzClientInterface
{
    /**
     * @throws MinimapperzUnauthorizedException when the key does not open this event
     * @throws MinimapperzUnavailableException  when Minimapperz cannot be read
     */
    public function event(string $slug, string $apiKey): MinimapperzEvent;

    /**
     * The finished games of the event, newest first; with `$since`, only those that ended after it.
     *
     * @return list<MinimapperzGame>
     *
     * @throws MinimapperzUnauthorizedException when the key does not open this event
     * @throws MinimapperzUnavailableException  when Minimapperz cannot be read
     */
    public function games(string $slug, string $apiKey, ?\DateTimeImmutable $since): array;

    /**
     * Everyone who joined the event.
     *
     * @return list<MinimapperzPlayer>
     *
     * @throws MinimapperzUnauthorizedException when the key does not open this event
     * @throws MinimapperzUnavailableException  when Minimapperz cannot be read
     */
    public function players(string $slug, string $apiKey): array;

    /**
     * The ranking of the event, best first, by the event's own ranking mode.
     *
     * @return list<MinimapperzRankingEntry>
     *
     * @throws MinimapperzUnauthorizedException when the key does not open this event
     * @throws MinimapperzUnavailableException  when Minimapperz cannot be read
     */
    public function ranking(string $slug, string $apiKey): array;
}
