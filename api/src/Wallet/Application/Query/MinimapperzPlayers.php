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

/**
 * Story 49.3: everyone who joined the event on Minimapperz, and which member each one is - through the code they
 * typed. Those who match no active registrant earn nothing automatically; the admin can credit them by hand.
 *
 * @phpstan-type MinimapperzPlayerRow array{username: string, code: string|null, games: int, wins: int, member: string|null, linked: bool}
 */
final readonly class MinimapperzPlayers
{
    public function __construct(
        private MinimapperzSettingRepositoryInterface $settings,
        private MinimapperzCodesQueryInterface $codes,
        private MinimapperzClientInterface $client,
        private EventRepositoryInterface $events,
    ) {
    }

    /**
     * @return array{status: 'ok'|'not_configured'|'unauthorized'|'unreachable', players: list<MinimapperzPlayerRow>}
     *
     * @throws NotFoundException when the event does not exist
     */
    public function of(string $eventId): array
    {
        if (!$this->events->findById($eventId) instanceof Event) {
            throw new NotFoundException('Événement introuvable.', 'event_not_found');
        }
        $setting = $this->settings->find($eventId);
        $slug = $setting?->getSlug();
        $apiKey = $setting?->getApiKey();
        if (!$setting instanceof MinimapperzSetting || null === $slug || null === $apiKey) {
            return ['status' => 'not_configured', 'players' => []];
        }

        try {
            $players = $this->client->players($slug, $apiKey);
        } catch (MinimapperzUnauthorizedException) {
            return ['status' => 'unauthorized', 'players' => []];
        } catch (MinimapperzUnavailableException) {
            return ['status' => 'unreachable', 'players' => []];
        }

        $holders = [];
        foreach ($this->codes->codes($eventId) as $row) {
            if (null !== $row['userId']) {
                $holders[$row['code']] = $row;
            }
        }

        $rows = [];
        foreach ($players as $player) {
            $holder = null === $player->code ? null : ($holders[$player->code] ?? null);
            $rows[] = [
                'username' => $player->username,
                'code' => $player->code,
                'games' => $player->games,
                'wins' => $player->wins,
                'member' => $holder['displayName'] ?? null,
                'linked' => null !== $holder && $holder['registered'],
            ];
        }
        // The ones to look at first: not linked to anyone.
        usort($rows, static fn (array $a, array $b): int => [$a['linked'], mb_strtolower($a['username'])] <=> [$b['linked'], mb_strtolower($b['username'])]);

        return ['status' => 'ok', 'players' => $rows];
    }
}
