<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Command;

use App\GameSelection\Application\Port\TutorialImageQuotaInterface;
use App\GameSelection\Application\Support\InstallStepsReader;
use App\Shared\Infrastructure\Adapter\MinioStorageInterface;

/**
 * Stores an uploaded tutorial-step image in the MinIO media bucket and returns its key plus a presigned
 * URL for immediate preview (story 31.10). The key is what gets persisted on the step; the URL is
 * re-derived at read time by {@see InstallStepsReader}. Every account but an admin's works within a quota, as the
 * bucket has no delete and the images are served publicly (story 50.3).
 */
final readonly class UploadTutorialImageCommand
{
    public function __construct(
        private MinioStorageInterface $minioStorage,
        private TutorialImageQuotaInterface $quota,
        private string $minioMediaBucket,
        private int $minioPresignTtl,
    ) {
    }

    /** @return TutorialImageUpload|null null when the account has used up its quota */
    public function execute(string $key, string $contents, string $userId, bool $isAdmin): ?TutorialImageUpload
    {
        if (!$isAdmin && !$this->quota->consume($userId)) {
            return null;
        }

        $this->minioStorage->upload($this->minioMediaBucket, $key, $contents);

        return new TutorialImageUpload(
            $key,
            $this->minioStorage->presignedUrl($this->minioMediaBucket, $key, $this->minioPresignTtl),
        );
    }
}
