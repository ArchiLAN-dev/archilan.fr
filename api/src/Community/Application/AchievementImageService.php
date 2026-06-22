<?php

declare(strict_types=1);

namespace App\Community\Application;

use App\Shared\Infrastructure\MinioStorageInterface;

/**
 * Stores an admin-uploaded achievement image in the MinIO media bucket and returns a presigned URL for an
 * immediate preview (story 30.33). The returned key is what the admin form persists on the definition; the
 * upload is decoupled from create/update so the image can be picked before saving.
 */
final readonly class AchievementImageService
{
    public function __construct(
        private MinioStorageInterface $minioStorage,
        private AchievementImageUrlResolver $imageUrls,
        private string $minioMediaBucket,
    ) {
    }

    /**
     * @return string a presigned URL to the just-uploaded image
     */
    public function upload(string $key, string $contents): string
    {
        $this->minioStorage->upload($this->minioMediaBucket, $key, $contents);

        return (string) $this->imageUrls->resolve($key);
    }
}
