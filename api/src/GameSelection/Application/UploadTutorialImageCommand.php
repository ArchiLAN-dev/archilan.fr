<?php

declare(strict_types=1);

namespace App\GameSelection\Application;

use App\Shared\Infrastructure\MinioStorageInterface;

/**
 * Stores an uploaded tutorial-step image in the MinIO media bucket and returns its key plus a presigned
 * URL for immediate preview (story 31.10). The key is what gets persisted on the step; the URL is
 * re-derived at read time by {@see InstallStepsReader}.
 */
final readonly class UploadTutorialImageCommand
{
    public function __construct(
        private MinioStorageInterface $minioStorage,
        private string $minioMediaBucket,
        private int $minioPresignTtl,
    ) {
    }

    /**
     * @return array{key: string, url: string}
     */
    public function execute(string $key, string $contents): array
    {
        $this->minioStorage->upload($this->minioMediaBucket, $key, $contents);

        return [
            'key' => $key,
            'url' => $this->minioStorage->presignedUrl($this->minioMediaBucket, $key, $this->minioPresignTtl),
        ];
    }
}
