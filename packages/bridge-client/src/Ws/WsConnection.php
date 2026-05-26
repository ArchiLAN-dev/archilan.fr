<?php

declare(strict_types=1);

namespace Archilan\BridgeClient\Ws;

use Archilan\BridgeClient\Ws\Exception\WsConnectionException;
use Archilan\BridgeClient\Ws\Message\ApproveRestartRequest;
use Archilan\BridgeClient\Ws\Message\BridgeEvent;
use Archilan\BridgeClient\Ws\Message\BridgeMessage;
use Archilan\BridgeClient\Ws\Message\SnapshotMessage;
use WebSocket\Client as Socket;
use WebSocket\ConnectionException;
use WebSocket\TimeoutException;

/**
 * A live WebSocket connection to the bridge event bus.
 *
 * Obtained via WsClient::connect(). The initial snapshot is always available via snapshot().
 *
 * Inbound message types:
 *   - BridgeEvent           — real-time events (join, chat, item_send, hint, goal, …)
 *   - ApproveRestartRequest — server asks whether the client approves an AP restart
 *
 * Outbound:
 *   - sendCommand()  — send an AP server command (e.g. !hint, !release)
 *   - respond()      — reply to an ApproveRestartRequest
 */
final class WsConnection
{
    private bool $open = true;

    public function __construct(
        private readonly Socket $socket,
        private readonly SnapshotMessage $snapshotMessage,
    ) {
    }

    /**
     * The full state snapshot received when the connection was established.
     */
    public function snapshot(): SnapshotMessage
    {
        return $this->snapshotMessage;
    }

    /**
     * Blocking event loop. Dispatches every incoming message to the appropriate callback
     * until the connection closes or close() is called.
     *
     * ApproveRestartRequest is handled automatically: $onApproveRestart is invoked and the
     * response is sent back. If no callback is provided, the request is rejected.
     *
     * @param callable(BridgeEvent): void                  $onEvent
     * @param callable(ApproveRestartRequest): bool|null   $onApproveRestart  Return true to approve.
     * @param callable(): void|null                        $onClose           Called on clean exit or error.
     *
     * @throws WsConnectionException if a fatal network error occurs (not a normal close)
     */
    public function listen(
        callable $onEvent,
        ?callable $onApproveRestart = null,
        ?callable $onClose = null,
    ): void {
        try {
            foreach ($this->messages() as $message) {
                if ($message instanceof ApproveRestartRequest) {
                    $approved = null !== $onApproveRestart
                        ? (bool) $onApproveRestart($message)
                        : false;
                    $this->respond($message->requestId, $approved);
                } elseif ($message instanceof BridgeEvent) {
                    $onEvent($message);
                }
            }
        } finally {
            if (null !== $onClose) {
                $onClose();
            }
        }
    }

    /**
     * Lazy generator that yields every incoming BridgeMessage until the connection closes.
     *
     * Prefer this over listen() when you need composable iteration, early break, or
     * interleaved sends between messages.
     *
     * ApproveRestartRequest messages are yielded like any other — the caller is responsible
     * for calling respond() on them, or use listen() to have that handled automatically.
     *
     * Usage:
     *
     *   foreach ($conn->messages() as $msg) {
     *       if ($msg instanceof BridgeEvent && $msg->is(BridgeEventType::Feed)) {
     *           echo $msg->payload['message'] ?? '';
     *       }
     *       if ($done) break; // closes the generator cleanly
     *   }
     *
     * @return \Generator<int, BridgeMessage, null, void>
     */
    public function messages(): \Generator
    {
        while ($this->open) {
            try {
                $message = $this->receiveOne();
            } catch (WsConnectionException) {
                return;
            }
            if (null !== $message) {
                yield $message;
            }
        }
    }

    /**
     * Blocks until exactly one message arrives. Skips control frames and empty payloads.
     *
     * @throws WsConnectionException when the connection is lost or was already closed
     */
    public function receive(): BridgeMessage
    {
        while ($this->open) {
            $message = $this->receiveOne();
            if (null !== $message) {
                return $message;
            }
        }

        throw new WsConnectionException('Connection closed before a message could be received');
    }

    /**
     * Sends an AP server command (e.g. "!help", "!hint Slot ItemName").
     *
     * @param string      $text  The command text.
     * @param string|null $id    Optional correlation ID — the bridge echoes it in the ack frame.
     *
     * @throws WsConnectionException on send failure
     */
    public function sendCommand(string $text, ?string $id = null): void
    {
        /** @var array<string, mixed> $frame */
        $frame = ['type' => 'command', 'text' => $text];
        if (null !== $id) {
            $frame['id'] = $id;
        }
        $this->sendFrame($frame);
    }

    /**
     * Responds to an ApproveRestartRequest.
     *
     * @throws WsConnectionException on send failure
     */
    public function respond(string $requestId, bool $approved): void
    {
        $this->sendFrame(['type' => 'response', 'id' => $requestId, 'approved' => $approved]);
    }

    /**
     * Closes the connection gracefully. Safe to call multiple times.
     */
    public function close(): void
    {
        if ($this->open) {
            $this->open = false;
            try {
                $this->socket->close();
            } catch (\Throwable) {
                // best-effort: the server may already have closed
            }
        }
    }

    public function isOpen(): bool
    {
        return $this->open;
    }

    // ------------------------------------------------------------------
    // Internal helpers
    // ------------------------------------------------------------------

    /**
     * Reads one frame from the socket. Returns null for unparseable or empty frames.
     *
     * @throws WsConnectionException on network failure
     */
    private function receiveOne(): ?BridgeMessage
    {
        try {
            $raw = $this->socket->receive();
        } catch (TimeoutException) {
            return null; // read timeout — no data yet, keep looping
        } catch (ConnectionException $e) {
            $this->open = false;
            throw new WsConnectionException('Connection lost: '.$e->getMessage(), previous: $e);
        }

        if (!is_string($raw) || '' === $raw) {
            return null;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }

        return $this->parseFrame($data);
    }

    /**
     * @param array<mixed, mixed> $frame
     */
    private function parseFrame(array $frame): BridgeMessage
    {
        $type = is_string($frame['type'] ?? null) ? $frame['type'] : '';

        if ('request' === $type && 'approve_restart' === ($frame['action'] ?? null)) {
            return new ApproveRestartRequest(
                requestId: is_string($frame['id'] ?? null) ? $frame['id'] : '',
            );
        }

        $payload = $frame;
        unset($payload['type']);

        return new BridgeEvent($type, $payload);
    }

    /**
     * @param array<string, mixed> $frame
     *
     * @throws WsConnectionException on encode or send failure
     */
    private function sendFrame(array $frame): void
    {
        $json = json_encode($frame);
        if (false === $json) {
            throw new WsConnectionException('Failed to JSON-encode outgoing frame');
        }

        try {
            $this->socket->send($json);
        } catch (ConnectionException $e) {
            $this->open = false;
            throw new WsConnectionException('Send failed: '.$e->getMessage(), previous: $e);
        }
    }
}
