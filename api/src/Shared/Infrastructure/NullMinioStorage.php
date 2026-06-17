<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure;

final class NullMinioStorage implements MinioStorageInterface
{
    /** @var array<string, string> key = "{bucket}/{key}", value = contents */
    private static array $store = [];

    private static int $presignCounter = 0;

    public static function reset(): void
    {
        self::$store = [];
        self::$presignCounter = 0;
    }

    public function upload(string $bucket, string $key, string $contents): void
    {
        self::$store["{$bucket}/{$key}"] = $contents;
    }

    public function download(string $bucket, string $key): string
    {
        $storeKey = "{$bucket}/{$key}";
        if (!array_key_exists($storeKey, self::$store)) {
            throw new \RuntimeException("MinIO key not found: {$storeKey}");
        }

        return self::$store[$storeKey];
    }

    public function exists(string $bucket, string $key): bool
    {
        return array_key_exists("{$bucket}/{$key}", self::$store);
    }

    public function presignedUrl(string $bucket, string $key, int $ttlSeconds): string
    {
        // Mimic real S3/MinIO presigning: the signature changes on every call,
        // even for the same object. Callers must rely on the path, not URL equality.
        $nonce = ++self::$presignCounter;

        return sprintf('http://minio.test/%s/%s?X-Amz-Expires=%d&X-Amz-Signature=stub%d', $bucket, $key, $ttlSeconds, $nonce);
    }

    /** @return array<string, string> */
    public function getStore(): array
    {
        return self::$store;
    }
}
