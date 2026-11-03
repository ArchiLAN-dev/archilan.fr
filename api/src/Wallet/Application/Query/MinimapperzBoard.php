<?php

declare(strict_types=1);

namespace App\Wallet\Application\Query;

use App\Events\Domain\Entity\Event;
use App\Events\Domain\Repository\EventRepositoryInterface;
use App\Shared\Application\Exception\NotFoundException;
use App\Wallet\Application\Exception\MinimapperzUnauthorizedException;
use App\Wallet\Application\Exception\MinimapperzUnavailableException;
use App\Wallet\Application\Port\MinimapperzClientInterface;
use App\Wallet\Domain\Entity\MinimapperzSetting;
use App\Wallet\Domain\Repository\MinimapperzSettingRepositoryInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Story 49.4: the Minimapperz board of an event for the régie screen - the ranking and the last games, public. The
 * site relays Minimapperz (the key never leaves the API) and keeps the answer a few seconds, so any number of screens
 * cost Minimapperz two calls per event and per period. A player linked by their code shows under their site name.
 *
 * @phpstan-type MinimapperzBoardLine array{rank: int, name: string, score: float, games: int, wins: int}
 * @phpstan-type MinimapperzBoardGame array{id: string, playedAt: string, winner: string|null, players: int}
 * @phpstan-type MinimapperzBoardView array{status: 'ok'|'unavailable'|'unreachable', eventTitle: string, ranking: list<MinimapperzBoardLine>, recent: list<MinimapperzBoardGame>}
 */
final readonly class MinimapperzBoard
{
    public const int CACHE_SECONDS = 20;
    public const int MAX_LINES = 20;
    public const int RECENT_GAMES = 5;

    public function __construct(
        private MinimapperzSettingRepositoryInterface $settings,
        private MinimapperzCodesQueryInterface $codes,
        private MinimapperzClientInterface $client,
        private EventRepositoryInterface $events,
        private CacheInterface $cache,
    ) {
    }

    /**
     * @return MinimapperzBoardView
     *
     * @throws NotFoundException when the event does not exist
     */
    public function of(string $eventId): array
    {
        $event = $this->events->findById($eventId);
        if (!$event instanceof Event) {
            throw new NotFoundException('Événement introuvable.', 'event_not_found');
        }

        /** @var MinimapperzBoardView $board */
        $board = $this->cache->get('minimapperz_board_'.hash('xxh128', $eventId), function (ItemInterface $item) use ($event): array {
            $item->expiresAfter(self::CACHE_SECONDS);

            return $this->read($event);
        });

        return $board;
    }

    /** @return MinimapperzBoardView */
    private function read(Event $event): array
    {
        $empty = ['eventTitle' => $event->getTitle(), 'ranking' => [], 'recent' => []];
        $setting = $this->settings->find($event->getId());
        $slug = $setting?->getSlug();
        $apiKey = $setting?->getApiKey();
        if (!$setting instanceof MinimapperzSetting || !$setting->isEnabled() || null === $slug || null === $apiKey) {
            return ['status' => 'unavailable'] + $empty;
        }

        try {
            $ranking = $this->client->ranking($slug, $apiKey);
            $games = $this->client->games($slug, $apiKey, null);
        } catch (MinimapperzUnauthorizedException|MinimapperzUnavailableException) {
            return ['status' => 'unreachable'] + $empty;
        }

        $names = [];
        foreach ($this->codes->codes($event->getId()) as $row) {
            if (null !== $row['displayName']) {
                $names[$row['code']] = $row['displayName'];
            }
        }
        $name = static fn (string $username, ?string $code): string => null === $code ? $username : ($names[$code] ?? $username);

        $lines = [];
        foreach (array_slice($ranking, 0, self::MAX_LINES) as $entry) {
            $lines[] = ['rank' => $entry->rank, 'name' => $name($entry->username, $entry->code), 'score' => $entry->score, 'games' => $entry->games, 'wins' => $entry->wins];
        }

        usort($games, static fn ($a, $b): int => $b->playedAt <=> $a->playedAt);
        $recent = [];
        foreach (array_slice($games, 0, self::RECENT_GAMES) as $game) {
            $winner = null;
            foreach ($game->players as $player) {
                if ($player->won) {
                    $winner = $name($player->username, $player->code);
                    break;
                }
            }
            $recent[] = [
                'id' => $game->id,
                'playedAt' => $game->playedAt->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM),
                'winner' => $winner,
                'players' => count($game->players),
            ];
        }

        return ['status' => 'ok', 'eventTitle' => $event->getTitle(), 'ranking' => $lines, 'recent' => $recent];
    }
}
