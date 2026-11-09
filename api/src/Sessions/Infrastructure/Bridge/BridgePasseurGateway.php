<?php

declare(strict_types=1);

namespace App\Sessions\Infrastructure\Bridge;

use App\Sessions\Application\Port\PasseurBridgeInterface;
use App\Sessions\Domain\ValueObject\PasseurItem;
use App\Shared\Application\Support\BridgeEndpoint;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Story 52.3: the bridge's Passeur routes (`/slots/{slot}/passeur/items` and `/passeur/unlocks`), called
 * directly: they are internal and the bridge client package does not know them.
 */
final readonly class BridgePasseurGateway implements PasseurBridgeInterface
{
    /** Each measured candidate is a tracker run: a few seconds each on a warm daemon. */
    private const int UNLOCKS_TIMEOUT_SECONDS = 60;

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private string $bridgeInternalToken,
    ) {
    }

    public function items(string $sessionId, int $slotIndex): ?array
    {
        $payload = $this->call($sessionId, 'GET', sprintf('/slots/%d/passeur/items', $slotIndex), null, 10);
        $rows = $payload['items'] ?? null;
        if (!is_array($rows)) {
            return null;
        }

        $items = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $finder = $row['findingSlot'] ?? null;
            $locationId = $row['locationId'] ?? null;
            $itemId = $row['itemId'] ?? null;
            $flags = $row['flags'] ?? null;
            $sphere = $row['sphere'] ?? null;
            if (!is_int($finder) || !is_int($locationId) || !is_int($itemId) || !is_int($flags) || (null !== $sphere && !is_int($sphere))) {
                continue;
            }
            $items[] = new PasseurItem(
                $finder,
                is_string($row['findingPlayerName'] ?? null) ? $row['findingPlayerName'] : '',
                $locationId,
                is_string($row['locationName'] ?? null) ? $row['locationName'] : '',
                $itemId,
                is_string($row['itemName'] ?? null) ? $row['itemName'] : '',
                // Archipelago's ItemClassification.progression bit.
                0 !== ($flags & 1),
                $sphere,
            );
        }

        return $items;
    }

    public function gains(string $sessionId, int $slotIndex, array $candidates): ?array
    {
        $body = ['candidates' => array_map(
            static fn (PasseurItem $item): array => ['itemId' => $item->itemId, 'findingSlot' => $item->findingSlot, 'locationId' => $item->locationId],
            array_slice($candidates, 0, self::MAX_MEASURED),
        )];
        $payload = $this->call($sessionId, 'POST', sprintf('/slots/%d/passeur/unlocks', $slotIndex), $body, self::UNLOCKS_TIMEOUT_SECONDS);
        $baseline = $payload['baseline'] ?? null;
        $unlocks = $payload['unlocks'] ?? null;
        if (!is_int($baseline) || !is_array($unlocks)) {
            return null;
        }

        $gains = [];
        foreach ($unlocks as $unlock) {
            $finder = is_array($unlock) ? ($unlock['findingSlot'] ?? null) : null;
            $locationId = is_array($unlock) ? ($unlock['locationId'] ?? null) : null;
            $reachable = is_array($unlock) ? ($unlock['reachableNow'] ?? null) : null;
            if (is_int($finder) && is_int($locationId) && is_int($reachable)) {
                $gains[$finder.':'.$locationId] = max(0, $reachable - $baseline);
            }
        }

        return $gains;
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<array-key, mixed>|null
     */
    private function call(string $sessionId, string $method, string $path, ?array $body, int $timeout): ?array
    {
        $options = ['headers' => ['Authorization' => 'Bearer '.$this->bridgeInternalToken], 'timeout' => $timeout];
        if (null !== $body) {
            $options['json'] = $body;
        }

        try {
            $response = $this->httpClient->request($method, BridgeEndpoint::url($sessionId, $path), $options);
            if (200 !== $response->getStatusCode()) {
                $this->logger->info('passeur.bridge_refused', ['session' => $sessionId, 'path' => $path, 'status' => $response->getStatusCode()]);

                return null;
            }

            return $response->toArray(false);
        } catch (\Throwable $e) {
            $this->logger->warning('passeur.bridge_unreachable', ['session' => $sessionId, 'path' => $path, 'error' => $e->getMessage()]);

            return null;
        }
    }
}
