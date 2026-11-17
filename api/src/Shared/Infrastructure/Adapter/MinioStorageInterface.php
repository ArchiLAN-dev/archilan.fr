<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Adapter;

interface MinioStorageInterface
{
    public function upload(string $bucket, string $key, string $contents): void;

    public function download(string $bucket, string $key): string;

    public function exists(string $bucket, string $key): bool;

    /** Removes an object; an object already gone is not an error (story 54.3). */
    public function delete(string $bucket, string $key): void;

    /** @return string pre-signed URL valid for $ttlSeconds seconds */
    public function presignedUrl(string $bucket, string $key, int $ttlSeconds): string;
}
