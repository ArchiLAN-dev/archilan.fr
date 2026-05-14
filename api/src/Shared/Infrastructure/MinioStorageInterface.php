<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure;

interface MinioStorageInterface
{
    public function upload(string $bucket, string $key, string $contents): void;

    public function download(string $bucket, string $key): string;

    public function exists(string $bucket, string $key): bool;

    /** @return string pre-signed URL valid for $ttlSeconds seconds */
    public function presignedUrl(string $bucket, string $key, int $ttlSeconds): string;
}
