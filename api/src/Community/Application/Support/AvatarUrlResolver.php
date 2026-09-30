<?php

declare(strict_types=1);

namespace App\Community\Application\Support;

use App\Community\Domain\Service\CustomImageRule;
use App\Shared\Infrastructure\Adapter\MinioStorageInterface;

/**
 * Resolves the avatar URL shown to clients, applying the story 30.27 precedence: a member-uploaded custom
 * avatar (presigned from the private media bucket) wins over the cached external URL (Discord/Steam).
 * Returns null when neither is set - the frontend then renders a deterministic default avatar from the slug.
 *
 * Presigning is best-effort: if the storage is unreachable, fall back to the external URL rather than break
 * the page (consistent with the avatar pipeline being non-blocking, story 30.2).
 */
final readonly class AvatarUrlResolver
{
    public function __construct(
        private MinioStorageInterface $minioStorage,
        private string $minioMediaBucket,
        private int $minioPresignTtl,
    ) {
    }

    public function resolve(?string $customAvatarKey, ?string $cachedExternalUrl): ?string
    {
        if (null !== $customAvatarKey && '' !== $customAvatarKey) {
            try {
                return $this->minioStorage->presignedUrl($this->minioMediaBucket, $customAvatarKey, $this->minioPresignTtl);
            } catch (\Throwable) {
                return $cachedExternalUrl;
            }
        }

        return $cachedExternalUrl;
    }

    /**
     * Story 30.40, for the card surfaces that read raw rows (directory, leaderboards): a GIF avatar shows its first
     * frame once the account (roles as the JSON stored on the user row) is no longer admin.
     */
    public function resolveForRow(mixed $customAvatarKey, mixed $customAvatarStillKey, mixed $rawRoles, mixed $cachedExternalUrl): ?string
    {
        $roles = is_string($rawRoles) ? json_decode($rawRoles, true) : null;
        $isAdmin = is_array($roles) && in_array('ROLE_ADMIN', $roles, true);

        return $this->resolve(
            CustomImageRule::displayedAvatarKey(
                is_string($customAvatarKey) ? $customAvatarKey : null,
                is_string($customAvatarStillKey) ? $customAvatarStillKey : null,
                $isAdmin,
            ),
            is_string($cachedExternalUrl) ? $cachedExternalUrl : null,
        );
    }
}
