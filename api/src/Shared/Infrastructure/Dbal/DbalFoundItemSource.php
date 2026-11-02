<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Dbal;

/**
 * The items of the session feed that a player really found for another slot (stories 30.49, 41.64), so the
 * achievement « items reçus d'autres joueurs » and the quests on items traded read the same rules.
 *
 * Not counted, as nobody found those items:
 *  - what a slot sent from its release (or forfeit) on, and what a slot received from its collect on, both dated on
 *    the slot since story 30.49 (two seconds of margin: the announcement can arrive after the first items);
 *  - for a slot with no such date (released before that story), a burst of BURST items or more in the same second
 *    from a slot that could release then (marked released, or past its goal: the release on goal) or into a slot that
 *    could collect then (past its goal). Ten real checks in a second during the game still count.
 */
final class DbalFoundItemSource
{
    public const int BURST = 10;

    public const string ITEM_TYPE = 'item-received';

    /**
     * The feed's items with the size of their bursts, as a table expression to alias. Bound with `:itemType`; a
     * `$where` on `fe` (a period) narrows it without changing the bursts, which never span more than a second.
     */
    public static function feed(string $where = ''): string
    {
        return '(SELECT fe.id, fe.session_id, fe.sender_name, fe.receiver_name, fe.occurred_at,
                        COUNT(*) OVER (PARTITION BY fe.session_id, fe.sender_name, fe.occurred_at) AS burst_out,
                        COUNT(*) OVER (PARTITION BY fe.session_id, fe.receiver_name, fe.occurred_at) AS burst_in
                   FROM session_feed_event fe
                  WHERE fe.type = :itemType '.$where.')';
    }

    /**
     * The conditions that drop released and collected items, for the feed line `$f`, its sending slot `$sender` and
     * its receiving slot `$receiver`. Bound with `:burst`.
     *
     * @return list<string>
     */
    public static function found(string $f, string $sender, string $receiver): array
    {
        return [
            "{$sender}.released_at IS NULL OR {$f}.occurred_at < {$sender}.released_at - INTERVAL '2 seconds'",
            "{$receiver}.collected_at IS NULL OR {$f}.occurred_at < {$receiver}.collected_at - INTERVAL '2 seconds'",
            // An undated release: a burst from a slot marked released, or that had already reached its goal.
            "{$f}.burst_out < :burst OR {$sender}.released_at IS NOT NULL OR NOT ({$sender}.was_released OR ({$sender}.goal_reached_at IS NOT NULL AND {$f}.occurred_at >= {$sender}.goal_reached_at))",
            // An undated collect: a burst into a slot that had already reached its goal (a collect needs it).
            "{$f}.burst_in < :burst OR {$receiver}.collected_at IS NOT NULL OR {$receiver}.goal_reached_at IS NULL OR {$f}.occurred_at < {$receiver}.goal_reached_at",
        ];
    }

    /** @return array{itemType: string, burst: int} */
    public static function params(): array
    {
        return ['itemType' => self::ITEM_TYPE, 'burst' => self::BURST];
    }
}
