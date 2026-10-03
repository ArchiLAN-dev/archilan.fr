<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Dbal;

use Doctrine\DBAL\ArrayParameterType;

/**
 * The checks of the session feed, each tied to the players of its slot (stories 42.1, 42.2).
 *
 * The feed names the sending slot by its name, the link RecordSessionFeedEvent already uses; the slot leads to
 * its owner and co-players through {@see DbalSlotPlayerSource}, the source points and achievements share. A
 * check is an item found for someone or the slot's own goal (the `item-received` and `goal` feed types of
 * SessionFeedEvent, spelled out here so Shared does not depend on Sessions).
 *
 * In the fragment, `f` is the feed line, `slot` the session slot and `sp` its players.
 */
final class DbalSlotCheckSource
{
    public const array CHECK_TYPES = ['item-received', 'goal'];

    /** A FROM ... WHERE fragment, to be bound with {@see params()} and {@see types()}. */
    public static function from(): string
    {
        return 'FROM session_feed_event f
                JOIN session_slot slot ON slot.session_id = f.session_id AND slot.slot_name = f.sender_name
                JOIN '.DbalSlotPlayerSource::expression('session_slot', 'registration').' sp ON sp.'.DbalSlotPlayerSource::SLOT_COLUMN.' = slot.id
               WHERE f.type IN (:checkTypes)';
    }

    /** @return array{checkTypes: list<string>} */
    public static function params(): array
    {
        return ['checkTypes' => self::CHECK_TYPES];
    }

    /** @return array{checkTypes: ArrayParameterType::STRING} */
    public static function types(): array
    {
        return ['checkTypes' => ArrayParameterType::STRING];
    }

    /** The player column, for COUNT(DISTINCT ...). */
    public static function player(): string
    {
        return 'sp.'.DbalSlotPlayerSource::USER_COLUMN;
    }
}
