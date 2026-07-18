<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Command;

use App\GameSelection\Application\Support\InstallStepsReader;
use App\Shared\Infrastructure\Adapter\MinioStorageInterface;

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

    public function execute(string $key, string $contents): TutorialImageUpload
    {
        $this->minioStorage->upload($this->minioMediaBucket, $key, $contents);

        return new TutorialImageUpload(
            $key,
            $this->minioStorage->presignedUrl($this->minioMediaBucket, $key, $this->minioPresignTtl),
        );
    }
}
