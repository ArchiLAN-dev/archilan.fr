<?php

declare(strict_types=1);

namespace Archilan\BridgeClient\Ws\Message;

/**
 * Marker interface for all messages received from the bridge WebSocket.
 *
 * Concrete types: SnapshotMessage, BridgeEvent, ApproveRestartRequest.
 */
interface BridgeMessage
{
}
