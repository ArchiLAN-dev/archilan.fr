<?php

declare(strict_types=1);

namespace App\Community\Application;

use App\Shared\Infrastructure\MinioStorageInterface;

/**
 * Resolves the presigned URL of an achievement's optional custom image (story 30.33), from its MinIO key in
 * the private media bucket. Returns null when no image is set (the frontend then renders the default
 * trophy). Presigning is best-effort: a storage hiccup yields null rather than breaking the page.
 */
final readonly class AchievementImageUrlResolver
{
    public function __construct(
        private MinioStorageInterface $minioStorage,
        private string $minioMediaBucket,
        private int $minioPresignTtl,
    ) {
    }

    public function resolve(?string $customImageKey): ?string
    {
        if (null === $customImageKey || '' === $customImageKey) {
            return null;
        }

        try {
            return $this->minioStorage->presignedUrl($this->minioMediaBucket, $customImageKey, $this->minioPresignTtl);
        } catch (\Throwable) {
            return null;
        }
    }
}
