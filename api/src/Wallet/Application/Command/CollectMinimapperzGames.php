<?php

declare(strict_types=1);

namespace App\Wallet\Application\Command;

use App\Events\Domain\Entity\Event;
use App\Events\Domain\Repository\EventRepositoryInterface;
use App\Shared\Application\Exception\ApplicationFailure;
use App\Shared\Application\Exception\ConflictException;
use App\Wallet\Application\Exception\MinimapperzUnauthorizedException;
use App\Wallet\Application\Exception\MinimapperzUnavailableException;
use App\Wallet\Application\Port\MinimapperzClientInterface;
use App\Wallet\Application\Query\EventPellesQueryInterface;
use App\Wallet\Application\Support\MinimapperzGame;
use App\Wallet\Application\Support\MinimapperzGamePlayer;
use App\Wallet\Domain\Entity\MinimapperzSetting;
use App\Wallet\Domain\Entity\PelleMovement;
use App\Wallet\Domain\Enum\PelleKind;
use App\Wallet\Domain\Enum\PelleReason;
use App\Wallet\Domain\Repository\MinimapperzCodeRepositoryInterface;
use App\Wallet\Domain\Repository\MinimapperzSettingRepositoryInterface;
use App\Wallet\Domain\Repository\PelleMovementRepositoryInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Story 49.3: every minute, the finished Minimapperz games of each event whose link is on, credited in event pelles to
 * the registrants who played them with their code (story 49.2).
 *
 * - A game is worth the event's pelles per game, plus its bonus when won, up to the cap per member: the game that
 *   crosses the cap pays what is left, the next ones nothing. Minimapperz's points never set an amount.
 * - Each (game, code) is credited once, whatever the passes that read it: the reading overlaps the previous one by a
 *   few minutes so a late clock loses nothing, and the movement's unique key absorbs the repeats.
 * - Only while the event runs, and only games that ended during it: the event's pelles are converted at its end,
 *   once per member, so a credit landing after that would never be converted.
 * - A refused key switches the link off (the admin sees why); Minimapperz not answering is retried at the next pass.
 */
final readonly class CollectMinimapperzGames
{
    public const string KEY_PREFIX = 'minimapperz:';
    private const string OVERLAP = 'PT10M';
    private const int MAX_KEY_LENGTH = 120;

    public function __construct(
        private MinimapperzSettingRepositoryInterface $settings,
        private MinimapperzCodeRepositoryInterface $codes,
        private MinimapperzClientInterface $client,
        private EventRepositoryInterface $events,
        private EventPellesQueryInterface $registrants,
        private RecordPelleMovement $record,
        private PelleMovementRepositoryInterface $movements,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function collectAll(): MinimapperzCollection
    {
        $now = $this->clock->now();
        $events = 0;
        $games = 0;
        $credited = 0;
        foreach ($this->settings->findEnabled() as $setting) {
            $pass = $this->collectEvent($setting, $now);
            if (null === $pass) {
                continue;
            }
            ++$events;
            $games += $pass->games;
            $credited += $pass->credited;
        }

        return new MinimapperzCollection($events, $games, $credited);
    }

    /** @return MinimapperzCollection|null null when the event was not read (not running, or no slug or key) */
    private function collectEvent(MinimapperzSetting $setting, \DateTimeImmutable $now): ?MinimapperzCollection
    {
        $slug = $setting->getSlug();
        $apiKey = $setting->getApiKey();
        $event = $this->events->findById($setting->getEventId());
        if (null === $slug || null === $apiKey || !$event instanceof Event || $now < $event->getStartsAt() || $now >= $event->getEndsAt()) {
            return null;
        }

        $since = $setting->getCollectedUntil()?->sub(new \DateInterval(self::OVERLAP));
        try {
            $games = $this->client->games($slug, $apiKey, $since);
        } catch (MinimapperzUnauthorizedException) {
            $setting->suspendForRefusedKey($now);
            $this->settings->save($setting);
            $this->logger->warning('wallet.minimapperz.key_refused', ['eventId' => $setting->getEventId()]);

            return null;
        } catch (MinimapperzUnavailableException $e) {
            $setting->recordUnreachable($now);
            $this->settings->save($setting);
            $this->logger->warning('wallet.minimapperz.unreachable', ['eventId' => $setting->getEventId(), 'reason' => $e->getMessage()]);

            return null;
        }

        $registrants = $this->registrants->activeRegistrantIds($setting->getEventId());
        $credited = 0;
        $newest = null;
        foreach ($games as $game) {
            if (null === $newest || $game->playedAt > $newest) {
                $newest = $game->playedAt;
            }
            if ($game->playedAt < $event->getStartsAt() || $game->playedAt >= $event->getEndsAt()) {
                continue;
            }
            foreach ($game->players as $player) {
                $credited += $this->creditPlayer($setting, $game, $player, $registrants);
            }
        }

        $setting->recordCollection($now, count($games), $credited, $newest);
        $this->settings->save($setting);

        return new MinimapperzCollection(1, count($games), $credited);
    }

    /**
     * @param list<string> $registrants
     *
     * @return int the pelles credited
     */
    private function creditPlayer(MinimapperzSetting $setting, MinimapperzGame $game, MinimapperzGamePlayer $player, array $registrants): int
    {
        $eventId = $setting->getEventId();
        $userId = null === $player->code ? null : $this->codes->holderOf($eventId, $player->code);
        if (null === $player->code || null === $userId || !in_array($userId, $registrants, true)) {
            return 0;
        }

        $key = self::uniqueKey($game->id, $player->code);
        if (null !== $this->movements->findByUniqueKey($key)) {
            return 0;
        }
        $cap = $setting->getCapPerPlayer();
        $left = $cap - $this->movements->totalOfReason($userId, $eventId, PelleReason::Minimapperz);
        $amount = min($left, $setting->getPellesPerGame() + ($player->won ? $setting->getPellesPerWin() : 0));
        if ($amount <= 0) {
            return 0;
        }

        try {
            $recorded = $this->record->record(
                new RecordPelleMovementInput($userId, $amount, PelleKind::Event, $eventId, PelleReason::Minimapperz, self::label($player), null, $key),
                function () use ($userId, $eventId, $cap): void {
                    if ($this->movements->totalOfReason($userId, $eventId, PelleReason::Minimapperz) > $cap) {
                        throw new ConflictException(sprintf('Plafond Minimapperz atteint (%d pelles).', $cap), 'cap_reached');
                    }
                },
            );
        } catch (ApplicationFailure $e) {
            // A banned or deleted member, or the cap reached in between: nothing for this game, the others go on.
            $this->logger->info('wallet.minimapperz.credit_skipped', ['eventId' => $eventId, 'userId' => $userId, 'game' => $game->id, 'reason' => $e->getMessage()]);

            return 0;
        }

        return $recorded->alreadyRecorded ? 0 : $amount;
    }

    /** One movement per (game, code); a code too long for the key is replaced by its hash. */
    public static function uniqueKey(string $gameId, string $code): string
    {
        $key = self::KEY_PREFIX.$gameId.':'.$code;

        return strlen($key) <= self::MAX_KEY_LENGTH ? $key : self::KEY_PREFIX.$gameId.':'.sha1($code);
    }

    private static function label(MinimapperzGamePlayer $player): string
    {
        $label = $player->won
            ? 'Minimapperz : partie gagnée'
            : sprintf('Minimapperz : partie jouée (%s)', 1 === $player->place ? '1re place' : sprintf('%de place', $player->place));

        return mb_substr($label, 0, PelleMovement::LABEL_MAX_LENGTH);
    }
}
