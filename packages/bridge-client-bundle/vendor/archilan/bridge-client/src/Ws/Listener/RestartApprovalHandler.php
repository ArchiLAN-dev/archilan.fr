<?php

declare(strict_types=1);

namespace Archilan\BridgeClient\Ws\Listener;

use Archilan\BridgeClient\Ws\Message\ApproveRestartRequest;

/**
 * Called when the bridge asks whether clients approve an automatic AP server restart.
 *
 * Return true to approve. The dispatcher sends back the response automatically.
 * If multiple subscribers implement this interface, one approval is sufficient (OR logic).
 *
 * If no subscriber implements this interface, the restart is rejected by default.
 */
interface RestartApprovalHandler extends WsSubscriber
{
    public function onApproveRestart(ApproveRestartRequest $request): bool;
}
