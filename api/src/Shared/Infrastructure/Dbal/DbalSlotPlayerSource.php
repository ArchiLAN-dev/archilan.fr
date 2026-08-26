<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Dbal;

/**
 * Who plays a session slot, as a joinable table expression (story 16.17).
 *
 * Points, level, leaderboard and achievements all counted a slot for exactly one member, because
 * they read `session_slot.registration_id` directly - the column that says who *declared* the slot.
 * A slot played by three people therefore scored for one. This expression is the single place that
 * turns a slot into its players, so the fix lands once instead of in every aggregate.
 *
 * It yields one row per (slot row, member):
 *
 *   - the owner, resolved through `registration.user_id` on an event session and taken as-is on a
 *     personal run, where `registration_id` already holds the member id (LaunchPersonalRunJobHandler);
 *   - every co-player, joined on the game slot id that `session_slot.slot_id` carries.
 *
 * Callers keep whatever join already scopes them to one surface - `registration` for event sessions,
 * `run` for personal ones - so the same expression serves both without double counting.
 */
final class DbalSlotPlayerSource
{
    /** Column naming the slot row the player is attached to (`session_slot.id`). */
    public const string SLOT_COLUMN = 'slot_row_id';

    /** Column naming the member. */
    public const string USER_COLUMN = 'uid';

    /**
     * A parenthesised sub-select, ready to hand to QueryBuilder::join() as the table expression.
     */
    public static function expression(string $slotTable, string $registrationTable, string $coPlayerTable = 'slot_co_player'): string
    {
        return '(SELECT sl.id AS '.self::SLOT_COLUMN.', COALESCE(reg.user_id, sl.registration_id) AS '.self::USER_COLUMN
            .' FROM '.$slotTable.' sl LEFT JOIN '.$registrationTable.' reg ON reg.id = sl.registration_id'
            .' UNION ALL '
            .'SELECT sl.id AS '.self::SLOT_COLUMN.', cp.user_id AS '.self::USER_COLUMN
            .' FROM '.$slotTable.' sl JOIN '.$coPlayerTable.' cp ON cp.slot_id = sl.slot_id)';
    }
}
