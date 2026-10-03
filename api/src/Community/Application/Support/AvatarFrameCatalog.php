<?php

declare(strict_types=1);

namespace App\Community\Application\Support;

use App\Community\Domain\Entity\AvatarFrameDefinition;
use App\Community\Domain\Enum\AvatarFrameAccess;
use App\Community\Domain\Repository\AvatarFrameDefinitionRepositoryInterface;
use App\Community\Domain\ValueObject\AvatarFrame;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The avatar frames as a whole (story 41.10): the code catalog (CSS frames and the built-in video frames of story
 * 30.46) and the video frames managed from the admin, which may also override a built-in one (name, access,
 * retirement). Every check of a frame goes through here.
 *
 * The admin rows are read once per request (avatar lists check one frame per card) and dropped between requests
 * and worker messages.
 */
final class AvatarFrameCatalog implements ResetInterface
{
    /** @var array<string, AvatarFrameDefinition>|null */
    private ?array $definitions = null;

    public function __construct(private readonly AvatarFrameDefinitionRepositoryInterface $repository)
    {
    }

    public function reset(): void
    {
        $this->definitions = null;
    }

    /** Whether a frame can be picked at all: a code frame, or a catalog frame that is not retired. */
    public function isValid(string $key): bool
    {
        $definition = $this->definitions()[$key] ?? null;
        if (null !== $definition) {
            return !$definition->isRetired();
        }

        return AvatarFrame::isValid($key);
    }

    /**
     * Whether this account may pick the frame.
     *
     * @param list<string> $owned the shop frames the account bought
     */
    public function allowedFor(string $key, bool $isAdmin, bool $isMember, array $owned): bool
    {
        $definition = $this->definitions()[$key] ?? null;
        if (null !== $definition) {
            return !$definition->isRetired() && $definition->getAccess()->allows($key, $isAdmin, $isMember, $owned);
        }

        return AvatarFrame::allowedFor($key, $isAdmin, $owned);
    }

    /** Why a frame was refused, for the profile form. */
    public function refusal(string $key): string
    {
        $access = ($this->definitions()[$key] ?? null)?->getAccess() ?? (AvatarFrame::isLegendary($key) ? AvatarFrameAccess::Admins : AvatarFrameAccess::Shop);

        return match ($access) {
            AvatarFrameAccess::Admins => 'Cadre réservé aux admins.',
            AvatarFrameAccess::Members => 'Cadre réservé aux adhérents.',
            default => 'Cadre à acheter en boutique.',
        };
    }

    /**
     * The frame a profile shows: none for a retired frame, or an admin-only one while its owner is not admin. The
     * stored key is kept, so the frame comes back with the right. Membership and purchases are checked when the
     * frame is picked: the avatar lists do not know them.
     */
    public function displayed(?string $key, bool $isAdmin): ?string
    {
        if (null === $key) {
            return null;
        }
        $definition = $this->definitions()[$key] ?? null;
        if (null === $definition) {
            return AvatarFrame::isValid($key) ? AvatarFrame::displayed($key, $isAdmin) : null;
        }
        if ($definition->isRetired() || (AvatarFrameAccess::Admins === $definition->getAccess() && !$isAdmin)) {
            return null;
        }

        return $key;
    }

    /**
     * The video frames - built-in and admin-managed, the built-in ones with the admin's override when there is one.
     *
     * @return list<array{key: string, label: string, access: AvatarFrameAccess, builtIn: bool, retired: bool, files: array{webm: string, mp4: string, poster: string, still: string}|null, position: int}>
     */
    public function videoFrames(): array
    {
        $definitions = $this->definitions();
        $frames = [];
        foreach (AvatarFrame::LEGENDARY as $position => $key) {
            $definition = $definitions[$key] ?? null;
            $frames[] = [
                'key' => $key,
                'label' => $definition?->getLabel() ?? AvatarFrame::LEGENDARY_LABELS[$key],
                'access' => $definition?->getAccess() ?? AvatarFrameAccess::Admins,
                'builtIn' => true,
                'retired' => $definition?->isRetired() ?? false,
                'files' => null,
                'position' => $definition?->getPosition() ?? $position,
            ];
        }
        foreach ($definitions as $key => $definition) {
            if (in_array($key, AvatarFrame::LEGENDARY, true)) {
                continue;
            }
            $frames[] = [
                'key' => $key,
                'label' => $definition->getLabel(),
                'access' => $definition->getAccess(),
                'builtIn' => false,
                'retired' => $definition->isRetired(),
                'files' => $definition->getFileKeys(),
                'position' => $definition->getPosition(),
            ];
        }
        usort($frames, static fn (array $a, array $b): int => [$a['position'], $a['key']] <=> [$b['position'], $b['key']]);

        return $frames;
    }

    /**
     * The frames the shop may sell: the code's shop frames and the catalog's, not retired.
     *
     * @return list<string>
     */
    public function sellableKeys(): array
    {
        $keys = AvatarFrame::SHOP;
        foreach ($this->definitions() as $key => $definition) {
            if (AvatarFrameAccess::Shop === $definition->getAccess() && !$definition->isRetired()) {
                $keys[] = $key;
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * @return array<string, AvatarFrameDefinition>
     */
    private function definitions(): array
    {
        if (null === $this->definitions) {
            $this->definitions = [];
            foreach ($this->repository->all() as $definition) {
                $this->definitions[$definition->getKey()] = $definition;
            }
        }

        return $this->definitions;
    }
}
