<?php

declare(strict_types=1);

namespace App\Sessions\Infrastructure\Adapter;

use App\Sessions\Application\Port\PublicReachabilitySnapshotsInterface;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Kept in a cache pool (config/packages/cache.yaml): the next push rewrites it, so losing it to a deployment costs an
 * overlay nothing but a few empty minutes.
 */
final readonly class CachePublicReachabilitySnapshots implements PublicReachabilitySnapshotsInterface
{
    public function __construct(private CacheItemPoolInterface $publicReachabilityCache)
    {
    }

    public function record(string $sessionId, int $slotIndex, array $view): void
    {
        $item = $this->publicReachabilityCache->getItem(self::key($sessionId, $slotIndex));
        $this->publicReachabilityCache->save($item->set($view));
    }

    public function find(string $sessionId, int $slotIndex): ?array
    {
        $item = $this->publicReachabilityCache->getItem(self::key($sessionId, $slotIndex));
        $view = $item->isHit() ? $item->get() : null;

        return is_array($view) ? $view : null;
    }

    private static function key(string $sessionId, int $slotIndex): string
    {
        return sprintf('%s.%d', hash('sha256', $sessionId), $slotIndex);
    }
}
