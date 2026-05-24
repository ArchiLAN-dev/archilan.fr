<?php

declare(strict_types=1);

namespace Archilan\BridgeClient\Room;

use Archilan\BridgeClient\Http\HttpTransport;
use Archilan\BridgeClient\Room\Response\FeedEvent;
use Archilan\BridgeClient\Room\Response\HealthResponse;
use Archilan\BridgeClient\Room\Response\RoomInfo;

final class RoomClient
{
    public function __construct(private readonly HttpTransport $transport)
    {
    }

    public function health(): HealthResponse
    {
        return HealthResponse::fromArray($this->transport->getJson('/health'));
    }

    public function info(): RoomInfo
    {
        return RoomInfo::fromArray($this->transport->getJson('/room'));
    }

    /**
     * @return FeedEvent[]
     */
    public function feed(int $limit = 50): array
    {
        $data = $this->transport->getJson('/feed?limit='.$limit);
        $events = [];
        foreach (is_array($data['events'] ?? null) ? $data['events'] : [] as $event) {
            if (is_array($event)) {
                /** @var array<string, mixed> $event */
                $events[] = FeedEvent::fromArray($event);
            }
        }

        return $events;
    }

    /**
     * @return string[]
     */
    public function dataPackageGames(): array
    {
        $data = $this->transport->getJson('/data-package');
        $games = $data['games'] ?? [];

        return array_values(array_filter(is_array($games) ? $games : [], 'is_string'));
    }

    /**
     * @return array<string, mixed>
     */
    public function dataPackage(string $game): array
    {
        return $this->transport->getJson('/data-package/'.urlencode($game));
    }
}
