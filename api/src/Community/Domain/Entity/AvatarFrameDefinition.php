<?php

declare(strict_types=1);

namespace App\Community\Domain\Entity;

use App\Community\Domain\Enum\AvatarFrameAccess;
use Doctrine\ORM\Mapping as ORM;

/**
 * A video frame managed from the admin (story 41.10): its name, who may wear it and, for a frame uploaded by an
 * admin, its four files in the public media bucket. A row whose key is a built-in video frame of the code (the
 * Légendaires of story 30.46) only overrides its name, access or retirement: its files stay the built-in ones (null
 * here). A frame is retired, never deleted - it may be worn or bought.
 */
#[ORM\Entity]
#[ORM\Table(name: 'avatar_frame')]
final class AvatarFrameDefinition
{
    public const int LABEL_MAX_LENGTH = 60;

    private function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'frame_key', type: 'string', length: 32)]
        private string $key,
        #[ORM\Column(type: 'string', length: self::LABEL_MAX_LENGTH)]
        private string $label,
        #[ORM\Column(type: 'string', length: 10, enumType: AvatarFrameAccess::class)]
        private AvatarFrameAccess $access,
        #[ORM\Column(name: 'webm_key', type: 'string', length: 255, nullable: true)]
        private ?string $webmKey,
        #[ORM\Column(name: 'mp4_key', type: 'string', length: 255, nullable: true)]
        private ?string $mp4Key,
        #[ORM\Column(name: 'poster_key', type: 'string', length: 255, nullable: true)]
        private ?string $posterKey,
        #[ORM\Column(name: 'still_key', type: 'string', length: 255, nullable: true)]
        private ?string $stillKey,
        #[ORM\Column(type: 'integer')]
        private int $position,
        #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $createdAt,
        #[ORM\Column(name: 'retired_at', type: 'datetimetz_immutable', nullable: true)]
        private ?\DateTimeImmutable $retiredAt = null,
    ) {
    }

    /** A new frame uploaded by an admin, with its four files. */
    public static function upload(string $key, string $label, AvatarFrameAccess $access, string $webmKey, string $mp4Key, string $posterKey, string $stillKey, int $position, \DateTimeImmutable $now): self
    {
        self::assertKey($key);

        return new self($key, self::cleanLabel($label), $access, $webmKey, $mp4Key, $posterKey, $stillKey, $position, $now);
    }

    /** An override of a built-in video frame of the code: its files stay the built-in ones. */
    public static function overrideBuiltIn(string $key, string $label, AvatarFrameAccess $access, int $position, \DateTimeImmutable $now): self
    {
        self::assertKey($key);

        return new self($key, self::cleanLabel($label), $access, null, null, null, null, $position, $now);
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

    public function hasOwnFiles(): bool
    {
        return null !== $this->webmKey;
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
     * @return array{webm: string, mp4: string, poster: string, still: string}|null null for a built-in override
     */
    public function getFileKeys(): ?array
    {
        if (null === $this->webmKey || null === $this->mp4Key || null === $this->posterKey || null === $this->stillKey) {
            return null;
        }

        return ['webm' => $this->webmKey, 'mp4' => $this->mp4Key, 'poster' => $this->posterKey, 'still' => $this->stillKey];
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
            throw new \DomainException('avatar_frame_key_invalid');
        }
    }

    private static function cleanLabel(string $label): string
    {
        $label = trim($label);
        if ('' === $label || mb_strlen($label) > self::LABEL_MAX_LENGTH) {
            throw new \DomainException('avatar_frame_label_invalid');
        }

        return $label;
    }
}
