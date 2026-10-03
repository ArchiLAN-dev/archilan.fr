<?php

declare(strict_types=1);

namespace App\Sessions\Infrastructure\Bridge;

use App\Sessions\Application\Exception\HintNotGivenException;
use App\Sessions\Application\Port\PelleHintGatewayInterface;
use App\Shared\Application\Support\BridgeEndpoint;
use Archilan\BridgeClient\BridgeClient;
use Archilan\BridgeClientBundle\Bridge\BridgeClientPool;

/**
 * The bridge's free hint (its admin `/hint` command), used once pelles are paid (story 41.3).
 *
 * That command is fire-and-forget: the bridge answers "ok" even when the server creates no hint (item already
 * found, already hinted, unknown name). So the slot's hint list is read before - a hint that already exists is
 * not sold again - and after, until an open hint shows up; none within a couple of seconds means it was not
 * given, and the caller refunds.
 */
final readonly class BridgePelleHintGateway implements PelleHintGatewayInterface
{
    private const int POLLS = 8;
    private const int POLL_INTERVAL_MICROSECONDS = 250_000;

    public function __construct(private BridgeClientPool $bridgeClientPool)
    {
    }

    public function hintItem(string $sessionId, int $slotIndex, string $itemName): void
    {
        $bridge = $this->bridge($sessionId);
        $this->give(
            fn (): string => BridgeHintEvidence::forItem($bridge->slots()->hints($slotIndex)->hints, $slotIndex, $itemName),
            fn () => $bridge->slots()->requestHintItem($slotIndex, $itemName, true),
        );
    }

    public function hintLocation(string $sessionId, int $slotIndex, int $locationId): void
    {
        $bridge = $this->bridge($sessionId);
        $this->give(
            fn (): string => BridgeHintEvidence::forLocation($bridge->slots()->hints($slotIndex)->hints, $slotIndex, $locationId),
            fn () => $bridge->slots()->requestHint($slotIndex, $locationId, true),
        );
    }

    /**
     * @param \Closure(): string $status
     * @param \Closure(): mixed  $request
     */
    private function give(\Closure $status, \Closure $request): void
    {
        match ($status()) {
            'open' => throw new HintNotGivenException(HintNotGivenException::ALREADY_HINTED),
            'found' => throw new HintNotGivenException(HintNotGivenException::ALREADY_FOUND),
            default => null,
        };

        $request();

        for ($poll = 0; $poll < self::POLLS; ++$poll) {
            usleep(self::POLL_INTERVAL_MICROSECONDS);
            $now = $status();
            if ('open' === $now) {
                return;
            }
            if ('found' === $now) {
                throw new HintNotGivenException(HintNotGivenException::ALREADY_FOUND);
            }
        }

        throw new HintNotGivenException(HintNotGivenException::NO_HINT_CREATED);
    }

    private function bridge(string $sessionId): BridgeClient
    {
        return $this->bridgeClientPool->get($sessionId, BridgeEndpoint::baseUrl($sessionId));
    }
}
