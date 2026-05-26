<?php

declare(strict_types=1);

namespace Archilan\BridgeClient\Ws;

/**
 * Known real-time event types broadcast by the bridge over WebSocket.
 *
 * Use tryFrom() to map an arbitrary string; unknown types simply return null
 * and are still surfaced as BridgeEvent so callers can handle future types.
 */
enum BridgeEventType: string
{
    /** Chat, print, or AP server messages (feed log). */
    case Feed             = 'feed';

    /** A slot's status, checks, items, or connection state changed. */
    case StateChanged     = 'state_changed';

    /** Room-level settings changed (hint cost, modes, …). */
    case RoomUpdated      = 'room_updated';

    /** A hint was added, updated, or found. */
    case HintsChanged     = 'hints_changed';

    /** A death-link was triggered. */
    case DeathLink        = 'death_link';

    /** Reachability analysis completed for a slot. */
    case ReachableChanged = 'reachable_changed';

    /** Periodic keep-alive emitted by the bridge every 30 s. */
    case Heartbeat        = 'heartbeat';

    /** AP server lifecycle transition: paused, restarted, or restart_failed. */
    case Lifecycle        = 'lifecycle';
}
