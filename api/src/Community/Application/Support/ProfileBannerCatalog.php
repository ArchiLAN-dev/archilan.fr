<?php

declare(strict_types=1);

namespace App\Community\Application\Support;

use App\Community\Domain\Entity\ProfileBannerDefinition;
use App\Community\Domain\Enum\AvatarFrameAccess;
use App\Community\Domain\Repository\ProfileBannerDefinitionRepositoryInterface;
use App\Community\Domain\ValueObject\BannerPreset;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The profile banners as a whole (story 41.11): the presets of the code and the banners managed from the admin,
 * which may also override a preset (name, access, order, retirement). Every check of a banner goes through here.
 * The admin rows are read once per request and dropped between requests and worker messages.
 */
final class ProfileBannerCatalog implements ResetInterface
{
    /** @var array<string, ProfileBannerDefinition>|null */
    private ?array $definitions = null;

    public function __construct(private readonly ProfileBannerDefinitionRepositoryInterface $repository)
    {
    }

    public function reset(): void
    {
        $this->definitions = null;
    }

    /** Whether a banner can be picked at all: a preset, or a catalog banner, not retired. */
    public function isValid(string $key): bool
    {
        $definition = $this->definitions()[$key] ?? null;
        if (null !== $definition) {
            return !$definition->isRetired();
        }

        return BannerPreset::isValid($key);
    }

    /**
     * Whether this account may pick the banner.
     *
     * @param list<string> $owned the shop banners the account bought
     */
    public function allowedFor(string $key, bool $isAdmin, bool $isMember, array $owned): bool
    {
        $definition = $this->definitions()[$key] ?? null;
        if (null !== $definition) {
            return !$definition->isRetired() && $definition->getAccess()->allows($key, $isAdmin, $isMember, $owned);
        }

        return BannerPreset::allowedFor($key, $owned);
    }

    /** Why a banner was refused, for the profile form. */
    public function refusal(string $key): string
    {
        return match (($this->definitions()[$key] ?? null)?->getAccess() ?? AvatarFrameAccess::Shop) {
            AvatarFrameAccess::Admins => 'Bannière réservée aux admins.',
            AvatarFrameAccess::Members => 'Bannière réservée aux adhérents.',
            AvatarFrameAccess::Reward => 'Bannière à gagner (succès ou quête).',
            default => 'Bannière à acheter en boutique.',
        };
    }

    /**
     * The banner a profile shows: the default one in place of a retired banner, or of an admin-only one while its
     * owner is not admin. The stored key is kept, so the banner comes back with the right. Membership and purchases
     * are checked when the banner is picked.
     */
    public function displayed(string $key, bool $isAdmin): string
    {
        $definition = $this->definitions()[$key] ?? null;
        if (null === $definition) {
            return BannerPreset::isValid($key) ? $key : BannerPreset::DEFAULT;
        }
        if ($definition->isRetired() || (AvatarFrameAccess::Admins === $definition->getAccess() && !$isAdmin)) {
            return BannerPreset::DEFAULT;
        }

        return $key;
    }

    /**
     * Every banner - presets and admin-managed ones, the presets with the admin's override when there is one.
     *
     * @return list<array{key: string, label: string, access: AvatarFrameAccess, builtIn: bool, retired: bool, files: array{image: string, webm: string|null, mp4: string|null}|null, position: int}>
     */
    public function banners(): array
    {
        $definitions = $this->definitions();
        $banners = [];
        foreach (BannerPreset::ALL as $position => $key) {
            $definition = $definitions[$key] ?? null;
            $banners[] = [
                'key' => $key,
                'label' => $definition?->getLabel() ?? BannerPreset::LABELS[$key],
                'access' => $definition?->getAccess() ?? (BannerPreset::allowedFor($key) ? AvatarFrameAccess::Free : AvatarFrameAccess::Shop),
                'builtIn' => true,
                'retired' => $definition?->isRetired() ?? false,
                'files' => null,
                'position' => $definition?->getPosition() ?? $position,
            ];
        }
        foreach ($definitions as $key => $definition) {
            if (BannerPreset::isValid($key)) {
                continue;
            }
            $banners[] = [
                'key' => $key,
                'label' => $definition->getLabel(),
                'access' => $definition->getAccess(),
                'builtIn' => false,
                'retired' => $definition->isRetired(),
                'files' => $definition->getFileKeys(),
                'position' => $definition->getPosition(),
            ];
        }
        usort($banners, static fn (array $a, array $b): int => [$a['position'], $a['key']] <=> [$b['position'], $b['key']]);

        return $banners;
    }

    /**
     * The banners the shop may sell: the code's shop presets and the catalog's, not retired.
     *
     * @return list<string>
     */
    public function sellableKeys(): array
    {
        $keys = BannerPreset::SHOP;
        foreach ($this->definitions() as $key => $definition) {
            if (AvatarFrameAccess::Shop === $definition->getAccess() && !$definition->isRetired()) {
                $keys[] = $key;
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * @return array<string, ProfileBannerDefinition>
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
