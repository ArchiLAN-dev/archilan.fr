<?php

declare(strict_types=1);

namespace App\Community\Application\Service;

use App\Community\Application\Port\ImageStillExtractor;
use App\Community\Application\Support\AvatarUrlResolver;
use App\Community\Domain\Entity\CommunityProfile;
use App\Community\Domain\Enum\CustomImageRefusal;
use App\Community\Domain\Enum\CustomImageSlot;
use App\Community\Domain\Enum\ImageFormat;
use App\Community\Domain\Repository\CommunityProfileRepositoryInterface;
use App\Community\Domain\Service\CustomImageRule;
use App\Community\Domain\Service\ImageInspector;
use App\Membership\Application\Query\ActiveMembershipQueryInterface;
use App\Shared\Infrastructure\Adapter\MinioStorageInterface;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Member-uploaded profile images: the avatar (story 30.27) and the banner image (story 30.40).
 *
 * The file's bytes decide its format (`ImageInspector`), `CustomImageRule` decides whether the member may put
 * it there. A GIF is stored as it is, and its first frame is extracted and stored beside it, ready for the day
 * its owner is no longer admin. The previous objects stay in place on replace/clear (no delete on the storage
 * port, like covers and tutorials). The profile row is created lazily, so a member can upload before ever
 * opening their profile.
 */
final readonly class CommunityCustomImageService
{
    public function __construct(
        private CommunityProfileRepositoryInterface $profiles,
        private MinioStorageInterface $minioStorage,
        private AvatarUrlResolver $imageUrls,
        private ImageStillExtractor $stills,
        private ActiveMembershipQueryInterface $memberships,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private string $minioMediaBucket,
    ) {
    }

    public function upload(CustomImageSlot $slot, string $userId, bool $isAdmin, string $bytes): CustomImageUpload
    {
        $image = ImageInspector::inspect($bytes);
        $isMember = CustomImageSlot::Banner === $slot && !$isAdmin && $this->memberships->hasActiveMembership($userId);
        $refusal = CustomImageRule::refusal($slot, $image, strlen($bytes), $isAdmin, $isMember);
        if (null !== $refusal || null === $image) {
            return CustomImageUpload::refused($refusal ?? CustomImageRefusal::UnsupportedType);
        }

        $base = sprintf('%s/%s', CustomImageSlot::Avatar === $slot ? 'community/avatars' : 'community/banners', bin2hex(random_bytes(16)));
        $key = $base.'.'.$image->format->value;
        $stillKey = null;

        try {
            $this->minioStorage->upload($this->minioMediaBucket, $key, $bytes);
            if (ImageFormat::Gif === $image->format) {
                $still = $this->stills->firstFrameAsPng($bytes);
                if (null === $still) {
                    return CustomImageUpload::refused(CustomImageRefusal::UnsupportedType);
                }
                $stillKey = $base.'-still.png';
                $this->minioStorage->upload($this->minioMediaBucket, $stillKey, $still);
            }
        } catch (\Throwable $exception) {
            // The client only sees storage_unavailable; the real cause (e.g. a missing bucket) is logged here.
            $this->logger->error('Community image upload to object storage failed.', ['key' => $key, 'exception' => $exception]);

            return CustomImageUpload::storageUnavailable();
        }

        $profile = $this->ensureProfile($userId);
        if (CustomImageSlot::Avatar === $slot) {
            $profile->uploadCustomAvatar($key, $stillKey, $this->clock->now());
        } else {
            $profile->uploadCustomBanner($key, $stillKey, $this->clock->now());
        }
        $this->profiles->flush();

        return CustomImageUpload::stored($this->imageUrls->resolve($key, null));
    }

    /**
     * Clear the member's avatar. Returns the resolved fallback URL (external cache, or null when the frontend
     * renders the default), so the caller can refresh its preview.
     */
    public function removeAvatar(string $userId): ?string
    {
        $profile = $this->profiles->findByUserId($userId);
        if (!$profile instanceof CommunityProfile) {
            return null;
        }

        if (null !== $profile->getCustomAvatarKey()) {
            $profile->removeCustomAvatar($this->clock->now());
            $this->profiles->flush();
        }

        return $this->imageUrls->resolve(null, $profile->getAvatarUrl());
    }

    /** Clear the banner image: the banner preset shows again. */
    public function removeBanner(string $userId): void
    {
        $profile = $this->profiles->findByUserId($userId);
        if ($profile instanceof CommunityProfile && null !== $profile->getCustomBannerKey()) {
            $profile->removeCustomBanner($this->clock->now());
            $this->profiles->flush();
        }
    }

    private function ensureProfile(string $userId): CommunityProfile
    {
        $existing = $this->profiles->findByUserId($userId);
        if (null !== $existing) {
            return $existing;
        }

        $profile = CommunityProfile::create($userId, $this->clock->now());
        try {
            $this->profiles->save($profile);

            return $profile;
        } catch (UniqueConstraintViolationException) {
            $reloaded = $this->profiles->findByUserId($userId);

            return $reloaded ?? $profile;
        }
    }
}
