<?php

declare(strict_types=1);

namespace Archilan\BridgeClient\Ws\Message;

/**
 * Server-initiated request asking whether the client approves an automatic AP restart.
 *
 * Respond with WsConnection::respond($request->requestId, true|false) within 5 seconds,
 * or pass an $onApproveRestart callback to WsConnection::listen() which handles it automatically.
 */
final readonly class ApproveRestartRequest implements BridgeMessage
{
    public function __construct(
        public string $requestId,
    ) {
    }
}
