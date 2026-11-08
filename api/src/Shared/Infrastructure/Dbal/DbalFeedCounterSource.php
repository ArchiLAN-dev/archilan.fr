<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Dbal;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;

/**
 * What a counter of checks or items gives one member, read in the session feed (story 30.57), so the achievements
 * and the weekly quests on a counter (story 41.72) share one set of rules. Shared so neither context depends on the
 * other: the counter's parts come in as plain values.
 *
 * - `location`: a check of one of the names, made by a slot the member plays (owner or co-player, through
 *   {@see DbalSlotPlayerSource}) in the counter's game (`sender_game`), once per session, slot and location.
 * - `item`: a copy of one of the names received by a slot the member plays, in the counter's game
 *   (`receiver_game`), his own finds included.
 * - With `withOthersOnly`, the other end of the line is a slot the member does not play.
 * - What a release or a collect handed over never counts: the rules of {@see DbalFoundItemSource}. The feed is
 *   narrowed to the sessions holding a match, never inside a session, so its bursts stay whole.
 * - A weekly attempt has no session slot: its lines tie to the member through `weekly_entries`, all its slots being
 *   his, and count until its goal (after it, what comes is the release and the collect). Never with
 *   `withOthersOnly`.
 *
 * Modes: `total` (every unit), `max_session` (the most units in one session), `distinct` (how many of the names).
 */
final class DbalFeedCounterSource
{
    public const string KIND_LOCATION = 'location';
    public const string KIND_ITEM = 'item';

    public const string MODE_TOTAL = 'total';
    public const string MODE_MAX_SESSION = 'max_session';
    public const string MODE_DISTINCT = 'distinct';

    /**
     * The query giving the counter's value for `:userId` as one integer column `n`, with its parameters and types.
     * A period (`$since` included, `$until` excluded) narrows it to the lines of that time.
     *
     * @param list<string> $names
     *
     * @return array{sql: string, params: array<string, mixed>, types: array<string, ArrayParameterType|ParameterType>}
     */
    public static function query(
        string $userId,
        string $gameName,
        string $kind,
        array $names,
        string $mode,
        bool $withOthersOnly,
        ?\DateTimeImmutable $since = null,
        ?\DateTimeImmutable $until = null,
    ): array {
        $location = self::KIND_LOCATION === $kind;
        $gameColumn = $location ? 'sender_game' : 'receiver_game';
        $nameColumn = $location ? 'location_name' : 'item_name';

        $period = (null !== $since ? ' AND %1$s.occurred_at >= :counterSince' : '').(null !== $until ? ' AND %1$s.occurred_at < :counterUntil' : '');
        $match = static fn (string $alias): string => "{$alias}.{$gameColumn} = :counterGame AND {$alias}.{$nameColumn} IN (:counterNames)".sprintf($period, $alias);

        $unit = static fn (string $alias): string => $location
            ? "{$alias}.session_id || ':' || {$alias}.sender_name || ':' || {$alias}.location_name"
            : "{$alias}.id";

        $players = DbalSlotPlayerSource::expression('session_slot', 'registration');
        $feed = DbalFoundItemSource::feed(
            'AND fe.session_id IN (SELECT m.session_id FROM session_feed_event m WHERE m.type = :itemType AND '.$match('m').')'
            .sprintf($period, 'fe'),
        );
        $own = $location ? 'ss' : 'rs';
        $other = $location ? 'rs' : 'ss';

        $sessions = 'SELECT f.session_id AS session_id, '.$unit('f').' AS unit, f.'.$nameColumn.' AS name
                       FROM '.$feed.' f
                       JOIN session_slot ss ON ss.session_id = f.session_id AND ss.slot_name = f.sender_name
                       JOIN session_slot rs ON rs.session_id = f.session_id AND rs.slot_name = f.receiver_name
                       JOIN '.$players.' own ON own.'.DbalSlotPlayerSource::SLOT_COLUMN.' = '.$own.'.id AND own.'.DbalSlotPlayerSource::USER_COLUMN.' = :userId
                      WHERE '.$match('f').'
                        AND '.implode(' AND ', array_map(static fn (string $rule): string => '('.$rule.')', DbalFoundItemSource::found('f', 'ss', 'rs')));
        if ($withOthersOnly) {
            $sessions .= ' AND '.$other.'.id <> '.$own.'.id
                           AND NOT EXISTS (SELECT 1 FROM '.$players.' op WHERE op.'.DbalSlotPlayerSource::SLOT_COLUMN.' = '.$other.'.id AND op.'.DbalSlotPlayerSource::USER_COLUMN.' = :userId)';
        }

        $rows = $sessions;
        if (!$withOthersOnly) {
            $rows .= ' UNION ALL
                      SELECT wf.session_id AS session_id, '.$unit('wf').' AS unit, wf.'.$nameColumn.' AS name
                        FROM session_feed_event wf
                        JOIN weekly_entries we ON we.external_session_id = wf.session_id AND we.user_id = :userId
                       WHERE wf.type = :itemType AND '.$match('wf').'
                         AND (we.goal_reached_at IS NULL OR wf.occurred_at < we.goal_reached_at)';
        }

        $sql = match ($mode) {
            self::MODE_DISTINCT => 'SELECT COUNT(DISTINCT r.name) AS n FROM ('.$rows.') r',
            self::MODE_MAX_SESSION => 'SELECT COALESCE(MAX(s.n), 0) AS n FROM (SELECT r.session_id, COUNT(DISTINCT r.unit) AS n FROM ('.$rows.') r GROUP BY r.session_id) s',
            default => 'SELECT COUNT(DISTINCT r.unit) AS n FROM ('.$rows.') r',
        };

        $params = [
            'userId' => $userId,
            'counterGame' => $gameName,
            'counterNames' => $names,
            'itemType' => DbalFoundItemSource::ITEM_TYPE,
            'burst' => DbalFoundItemSource::BURST,
        ];
        $types = ['counterNames' => ArrayParameterType::STRING, 'burst' => ParameterType::INTEGER];
        if (null !== $since) {
            $params['counterSince'] = $since->format(\DATE_ATOM);
        }
        if (null !== $until) {
            $params['counterUntil'] = $until->format(\DATE_ATOM);
        }

        return ['sql' => $sql, 'params' => $params, 'types' => $types];
    }
}
