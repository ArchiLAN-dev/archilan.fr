<?php

declare(strict_types=1);

namespace App\Community\Application;

use App\Community\Domain\CommunityProfile;
use App\Community\Domain\CommunityProfileRepositoryInterface;
use App\Shared\Infrastructure\MinioStorageInterface;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * Member-uploaded avatar management (story 30.27): store the image in the MinIO media bucket and point the
 * profile at it, or clear it to fall back to the resolved external avatar. The previous object is left in
 * place on replace/clear (consistent with covers/tutorials - no delete on the storage port). The profile
 * row is created lazily here so a member can upload before ever opening their profile.
 */
final readonly class CommunityAvatarService
{
    public function __construct(
        private CommunityProfileRepositoryInterface $profiles,
        private MinioStorageInterface $minioStorage,
        private AvatarUrlResolver $avatarUrls,
        private string $minioMediaBucket,
    ) {
    }

    /**
     * Store the uploaded bytes under $key and set it as the member's custom avatar.
     *
     * @return string a presigned URL to the just-uploaded avatar, for immediate preview
     */
    public function upload(string $userId, string $key, string $contents): string
    {
        $this->minioStorage->upload($this->minioMediaBucket, $key, $contents);

        $profile = $this->ensureProfile($userId);
        $profile->setCustomAvatar($key, new \DateTimeImmutable());
        $this->profiles->flush();

        return (string) $this->avatarUrls->resolve($key, null);
    }

    /**
     * Clear the member's custom avatar. Returns the resolved fallback URL (external cache, or null when the
     * frontend should render the default), so the caller can refresh its preview.
     */
    public function remove(string $userId): ?string
    {
        $profile = $this->profiles->findByUserId($userId);
        if (!$profile instanceof CommunityProfile) {
            return null;
        }

        if (null !== $profile->getCustomAvatarKey()) {
            $profile->setCustomAvatar(null, new \DateTimeImmutable());
            $this->profiles->flush();
        }

        return $this->avatarUrls->resolve(null, $profile->getAvatarUrl());
    }

    private function ensureProfile(string $userId): CommunityProfile
    {
        $existing = $this->profiles->findByUserId($userId);
        if (null !== $existing) {
            return $existing;
        }

        $profile = CommunityProfile::create($userId, new \DateTimeImmutable());
        try {
            $this->profiles->save($profile);

            return $profile;
        } catch (UniqueConstraintViolationException) {
            $reloaded = $this->profiles->findByUserId($userId);

            return $reloaded ?? $profile;
        }
    }
}
