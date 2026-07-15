<?php

declare(strict_types=1);

namespace App\Shared\Application\Support;

use App\Shared\Infrastructure\Adapter\MinioStorageInterface;

/**
 * Resolves a stable public URL for an object stored in the public media bucket.
 *
 * When a public base URL is configured (a CDN or the MinIO public endpoint), the URL is
 * stable and CDN-cacheable: `{baseUrl}/{key}`. When it is not configured yet (empty), the
 * resolver falls back to a presigned URL against the *public* bucket - the app keeps working
 * before the base URL is wired, the only difference being the URL is short-lived and rotates.
 */
final readonly class PublicMediaUrlResolver
{
    public function __construct(
        private MinioStorageInterface $minioStorage,
        private string $minioPublicMediaBucket,
        private string $publicMediaBaseUrl,
        private int $minioPresignTtl,
    ) {
    }

    /**
     * The bucket public media uploads must target.
     */
    public function bucket(): string
    {
        return $this->minioPublicMediaBucket;
    }

    /**
     * True when a stable public base URL is configured.
     */
    public function isStable(): bool
    {
        return '' !== $this->publicMediaBaseUrl;
    }

    /**
     * A public URL for the given object key - stable when configured, presigned otherwise.
     */
    public function resolve(string $key): string
    {
        if ('' !== $this->publicMediaBaseUrl) {
            return rtrim($this->publicMediaBaseUrl, '/').'/'.ltrim($key, '/');
        }

        return $this->minioStorage->presignedUrl($this->minioPublicMediaBucket, $key, $this->minioPresignTtl);
    }
}
