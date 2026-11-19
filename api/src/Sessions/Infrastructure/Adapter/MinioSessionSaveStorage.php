<?php

declare(strict_types=1);

namespace App\Sessions\Infrastructure\Adapter;

use App\Sessions\Application\Port\SessionSaveStorageInterface;
use App\Shared\Infrastructure\Adapter\MinioStorageInterface;

/**
 * The save sits at `{sessionId}/save/latest.apsave` in the sessions bucket, one object per session, overwritten by
 * the orchestrateur at each stop (story 17.37).
 */
final readonly class MinioSessionSaveStorage implements SessionSaveStorageInterface
{
    public function __construct(
        private MinioStorageInterface $storage,
        private string $minioSessionsBucket,
    ) {
    }

    public function locate(string $sessionId): ?string
    {
        $key = self::key($sessionId);
        try {
            return $this->storage->exists($this->minioSessionsBucket, $key) ? $key : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function read(string $sessionId): ?string
    {
        try {
            $contents = $this->storage->download($this->minioSessionsBucket, self::key($sessionId));
        } catch (\Throwable) {
            return null;
        }

        return '' === $contents ? null : $contents;
    }

    public static function key(string $sessionId): string
    {
        return $sessionId.'/save/latest.apsave';
    }
}
