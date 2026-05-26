<?php

declare(strict_types=1);

namespace Archilan\BridgeClient\Ws;

use Archilan\BridgeClient\Ws\Exception\WsAuthException;
use Archilan\BridgeClient\Ws\Exception\WsConnectionException;
use Archilan\BridgeClient\Ws\Message\SnapshotMessage;
use WebSocket\Client as Socket;
use WebSocket\ConnectionException;

/**
 * Factory for WsConnection.
 *
 * Converts the HTTP base URL (http://host:port) to a WebSocket URL
 * (ws://host:port/ws?token=…) and opens the connection.
 *
 * The bridge authenticates via a query-string token. An invalid token causes
 * the server to close the handshake with code 4001, which surfaces here as
 * WsAuthException.
 */
final class WsClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $token,
    ) {
    }

    /**
     * Opens a WebSocket connection to the bridge, waits for the initial snapshot,
     * and returns a ready-to-use WsConnection.
     *
     * @throws WsAuthException       when the bridge rejects the token (close code 4001)
     * @throws WsConnectionException on any other network or protocol error
     */
    public function connect(): WsConnection
    {
        $url = $this->wsUrl();

        try {
            $socket = new Socket($url);
            $raw    = $socket->receive();
        } catch (ConnectionException $e) {
            if (4001 === $e->getCode()) {
                throw new WsAuthException(
                    'Bridge rejected the connection: invalid token (close 4001)',
                    previous: $e,
                );
            }
            throw new WsConnectionException(
                'Could not connect to bridge WebSocket: '.$e->getMessage(),
                previous: $e,
            );
        }

        if (!is_string($raw)) {
            throw new WsConnectionException('Bridge sent a non-text frame as first message');
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || 'snapshot' !== ($data['type'] ?? null)) {
            throw new WsConnectionException(
                'Bridge sent an unexpected first message (expected snapshot, got: '.substr($raw, 0, 80).')',
            );
        }

        return new WsConnection($socket, SnapshotMessage::fromArray($data));
    }

    private function wsUrl(): string
    {
        $base = rtrim($this->baseUrl, '/');

        if (str_starts_with($base, 'https://')) {
            $base = 'wss://'.substr($base, 8);
        } elseif (str_starts_with($base, 'http://')) {
            $base = 'ws://'.substr($base, 7);
        }

        return $base.'/ws?token='.urlencode($this->token);
    }
}
