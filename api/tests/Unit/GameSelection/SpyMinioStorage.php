<?php

declare(strict_types=1);

namespace App\Tests\Unit\GameSelection;

use App\Shared\Infrastructure\Adapter\MinioStorageInterface;

/**
 * Records uploads in memory, or fails them all (story 38.6 tests).
 */
final class SpyMinioStorage implements MinioStorageInterface
{
    /** @var array<string, string> "bucket/key" => contents */
    public array $objects = [];

    public function __construct(private readonly bool $failing = false)
    {
    }

    public function upload(string $bucket, string $key, string $contents): void
    {
        if ($this->failing) {
            throw new \RuntimeException('MinIO unreachable');
        }
        $this->objects[$bucket.'/'.$key] = $contents;
    }

    public function download(string $bucket, string $key): string
    {
        return $this->objects[$bucket.'/'.$key] ?? '';
    }

    public function exists(string $bucket, string $key): bool
    {
        return \array_key_exists($bucket.'/'.$key, $this->objects);
    }

    public function presignedUrl(string $bucket, string $key, int $ttlSeconds): string
    {
        return 'https://minio.test/'.$bucket.'/'.$key;
    }
}
