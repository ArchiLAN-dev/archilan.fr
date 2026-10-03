<?php

declare(strict_types=1);

namespace App\Community\Domain\Entity;

use App\Community\Domain\Enum\AvatarFrameAccess;
use Doctrine\ORM\Mapping as ORM;

/**
 * A profile banner managed from the admin (story 41.11): its name, who may show it and, for a banner uploaded by an
 * admin, its files in the public media bucket - a still image, and for an animated banner a looped video in WebM and
 * MP4. A row whose key is a preset of the code only overrides its name, access, order or retirement (no files here).
 * A banner is retired, never deleted - it may be shown or bought.
 */
#[ORM\Entity]
#[ORM\Table(name: 'profile_banner')]
final class ProfileBannerDefinition
{
    public const int LABEL_MAX_LENGTH = 60;

    private function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'banner_key', type: 'string', length: 32)]
        private string $key,
        #[ORM\Column(type: 'string', length: self::LABEL_MAX_LENGTH)]
        private string $label,
        #[ORM\Column(type: 'string', length: 10, enumType: AvatarFrameAccess::class)]
        private AvatarFrameAccess $access,
        #[ORM\Column(name: 'image_key', type: 'string', length: 255, nullable: true)]
        private ?string $imageKey,
        #[ORM\Column(name: 'webm_key', type: 'string', length: 255, nullable: true)]
        private ?string $webmKey,
        #[ORM\Column(name: 'mp4_key', type: 'string', length: 255, nullable: true)]
        private ?string $mp4Key,
        #[ORM\Column(type: 'integer')]
        private int $position,
        #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $createdAt,
        #[ORM\Column(name: 'retired_at', type: 'datetimetz_immutable', nullable: true)]
        private ?\DateTimeImmutable $retiredAt = null,
    ) {
    }

    /**
     * A new banner uploaded by an admin: a still image, and both videos for an animated one.
     *
     * @param array{webm: string, mp4: string}|null $video
     */
    public static function upload(string $key, string $label, AvatarFrameAccess $access, string $imageKey, ?array $video, int $position, \DateTimeImmutable $now): self
    {
        self::assertKey($key);

        return new self($key, self::cleanLabel($label), $access, $imageKey, $video['webm'] ?? null, $video['mp4'] ?? null, $position, $now);
    }

    /** An override of a preset of the code: no files. */
    public static function overrideBuiltIn(string $key, string $label, AvatarFrameAccess $access, int $position, \DateTimeImmutable $now): self
    {
        self::assertKey($key);

        return new self($key, self::cleanLabel($label), $access, null, null, null, $position, $now);
    }

    public function update(?string $label, ?AvatarFrameAccess $access, ?int $position): void
    {
        if (null !== $label) {
            $this->label = self::cleanLabel($label);
        }
        if (null !== $access) {
            $this->access = $access;
        }
        if (null !== $position) {
            $this->position = $position;
        }
    }

    public function retire(\DateTimeImmutable $now): void
    {
        $this->retiredAt ??= $now;
    }

    public function restore(): void
    {
        $this->retiredAt = null;
    }

    public function isRetired(): bool
    {
        return null !== $this->retiredAt;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getAccess(): AvatarFrameAccess
    {
        return $this->access;
    }

    /**
     * @return array{image: string, webm: string|null, mp4: string|null}|null null for a preset override
     */
    public function getFileKeys(): ?array
    {
        if (null === $this->imageKey) {
            return null;
        }
        if (null === $this->webmKey || null === $this->mp4Key) {
            return ['image' => $this->imageKey, 'webm' => null, 'mp4' => null];
        }

        return ['image' => $this->imageKey, 'webm' => $this->webmKey, 'mp4' => $this->mp4Key];
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    private static function assertKey(string $key): void
    {
        if (1 !== preg_match('/^[a-z0-9_]{2,32}$/', $key)) {
            throw new \DomainException('profile_banner_key_invalid');
        }
    }

    private static function cleanLabel(string $label): string
    {
        $label = trim($label);
        if ('' === $label || mb_strlen($label) > self::LABEL_MAX_LENGTH) {
            throw new \DomainException('profile_banner_label_invalid');
        }

        return $label;
    }
}
