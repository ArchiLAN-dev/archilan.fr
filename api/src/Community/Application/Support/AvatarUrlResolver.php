<?php

declare(strict_types=1);

namespace App\Community\Application\Support;

use App\Community\Domain\Service\CustomImageRule;
use App\Community\Domain\ValueObject\AvatarFrame;
use App\Community\Domain\ValueObject\ImageFraming;
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
     * Story 30.42, for the card surfaces that read raw rows (directory, leaderboards): the still avatar (a GIF's
     * first frame) and, for an admin's GIF, the GIF to animate on hover, with its framing (story 30.43). The row
     * holds the user's `roles` JSON and the profile's `avatar_url`, `custom_avatar_key`, `custom_avatar_still_key`,
     * `avatar_framing_x` / `_y` / `_zoom` and `avatar_frame` columns (story 30.47: the frame follows the avatar
     * everywhere).
     *
     * @param array<string, mixed> $row
     *
     * @return array{avatarUrl: string|null, avatarAnimatedUrl: string|null, avatarFraming: array{x: int, y: int, zoom: int}|null, avatarFrame: string|null}
     */
    public function resolveForRow(array $row): array
    {
        $rawRoles = $row['roles'] ?? null;
        $roles = is_string($rawRoles) ? json_decode($rawRoles, true) : null;
        $key = is_string($row['custom_avatar_key'] ?? null) ? $row['custom_avatar_key'] : null;
        $stillKey = is_string($row['custom_avatar_still_key'] ?? null) ? $row['custom_avatar_still_key'] : null;
        $external = is_string($row['avatar_url'] ?? null) ? $row['avatar_url'] : null;
        $framing = new ImageFraming(
            $this->int($row['avatar_framing_x'] ?? null, 50),
            $this->int($row['avatar_framing_y'] ?? null, 50),
            $this->int($row['avatar_framing_zoom'] ?? null, ImageFraming::MIN_ZOOM),
        );

        $frame = is_string($row['avatar_frame'] ?? null) ? $row['avatar_frame'] : null;

        return $this->forCard($key, $stillKey, is_array($roles) && in_array('ROLE_ADMIN', $roles, true), $external, $framing, $frame);
    }

    /**
     * @return array{avatarUrl: string|null, avatarAnimatedUrl: string|null, avatarFraming: array{x: int, y: int, zoom: int}|null, avatarFrame: string|null}
     */
    public function forCard(?string $key, ?string $stillKey, bool $isAdmin, ?string $cachedExternalUrl, ImageFraming $framing, ?string $avatarFrame): array
    {
        $animatedKey = CustomImageRule::animatedAvatarKey($key, $stillKey, $isAdmin);

        return [
            'avatarUrl' => $this->resolve(CustomImageRule::cardAvatarKey($key, $stillKey), $cachedExternalUrl),
            'avatarAnimatedUrl' => null !== $animatedKey ? $this->resolve($animatedKey, null) : null,
            'avatarFraming' => self::framing($key, $framing),
            // Story 30.47: a legendary frame only while its owner is admin (story 30.46).
            'avatarFrame' => AvatarFrame::displayed($avatarFrame, $isAdmin),
        ];
    }

    /**
     * Story 30.43: the framing a client applies to an uploaded avatar; null (shown centred) for no upload or the
     * default framing, to keep the lists light.
     *
     * @return array{x: int, y: int, zoom: int}|null
     */
    public static function framing(?string $customAvatarKey, ImageFraming $framing): ?array
    {
        return null === $customAvatarKey || $framing->isCentred() ? null : $framing->toArray();
    }

    /** DBAL returns smallints as int or numeric string depending on the driver. */
    private function int(mixed $value, int $default): int
    {
        return is_int($value) ? $value : (is_string($value) && is_numeric($value) ? (int) $value : $default);
    }
}
