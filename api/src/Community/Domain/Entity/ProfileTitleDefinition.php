<?php

declare(strict_types=1);

namespace App\Community\Domain\Entity;

use App\Community\Domain\Enum\AvatarFrameAccess;
use Doctrine\ORM\Mapping as ORM;

/**
 * A profile title the admins write (story 41.22): a short text a member wears under their name, open to everyone,
 * members, admins, or bought in the shop. Retired, it can no longer be picked and leaves the profiles wearing it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'profile_title')]
final class ProfileTitleDefinition
{
    public const int MIN_LABEL = 2;
    public const int MAX_LABEL = 40;

    private function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'title_key', type: 'string', length: 32)]
        private string $key,
        #[ORM\Column(type: 'string', length: 40)]
        private string $label,
        #[ORM\Column(type: 'string', length: 10, enumType: AvatarFrameAccess::class)]
        private AvatarFrameAccess $access,
        #[ORM\Column(type: 'integer')]
        private int $position,
        #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $createdAt,
        #[ORM\Column(name: 'retired_at', type: 'datetimetz_immutable', nullable: true)]
        private ?\DateTimeImmutable $retiredAt = null,
    ) {
    }

    public static function write(string $key, string $label, AvatarFrameAccess $access, int $position, \DateTimeImmutable $now): self
    {
        if (1 !== preg_match('/^[a-z0-9][a-z0-9_-]{1,31}$/', $key)) {
            throw new \DomainException('profile_title_key_invalid');
        }

        return new self($key, self::assertLabel($label), $access, $position, $now);
    }

    public function update(?string $label, ?AvatarFrameAccess $access, ?int $position): void
    {
        if (null !== $label) {
            $this->label = self::assertLabel($label);
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

    public function getPosition(): int
    {
        return $this->position;
    }

    private static function assertLabel(string $label): string
    {
        $label = trim($label);
        $length = mb_strlen($label);
        if ($length < self::MIN_LABEL || $length > self::MAX_LABEL) {
            throw new \DomainException('profile_title_label_invalid');
        }

        return $label;
    }
}
