<?php

declare(strict_types=1);

namespace Archilan\BridgeClient;

use Archilan\BridgeClient\Admin\AdminClient;
use Archilan\BridgeClient\Http\HttpTransport;
use Archilan\BridgeClient\Room\RoomClient;
use Archilan\BridgeClient\Slots\SlotsClient;
use Archilan\BridgeClient\Ws\WsClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class BridgeClient
{
    private readonly HttpTransport $transport;
    private readonly RoomClient $roomClient;
    private readonly SlotsClient $slotsClient;
    private readonly AdminClient $adminClient;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $adminToken,
        HttpClientInterface $httpClient,
    ) {
        $this->transport    = new HttpTransport($httpClient, $baseUrl, $adminToken);
        $this->roomClient   = new RoomClient($this->transport);
        $this->slotsClient  = new SlotsClient($this->transport);
        $this->adminClient  = new AdminClient($this->transport);
    }

    public function room(): RoomClient
    {
        return $this->roomClient;
    }

    public function slots(): SlotsClient
    {
        return $this->slotsClient;
    }

    public function admin(): AdminClient
    {
        return $this->adminClient;
    }

    /**
     * Returns a factory for opening WebSocket connections to the bridge event bus.
     *
     * Usage:
     *   $conn = $client->ws()->connect();
     *   $conn->listen(fn(BridgeEvent $e) => var_dump($e->type, $e->payload));
     */
    public function ws(): WsClient
    {
        return new WsClient($this->baseUrl, $this->adminToken);
    }
}
