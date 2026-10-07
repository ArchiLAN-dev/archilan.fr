<?php

declare(strict_types=1);

namespace App\Community\Application\Support;

use App\Community\Domain\Entity\ProfileTitleDefinition;
use App\Community\Domain\Enum\AvatarFrameAccess;
use App\Community\Domain\Repository\ProfileTitleDefinitionRepositoryInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The profile titles (story 41.22), all written from the admin. Every check of a title goes through here; the rows
 * are read once per request and dropped between requests and worker messages.
 */
final class ProfileTitleCatalog implements ResetInterface
{
    /** @var array<string, ProfileTitleDefinition>|null */
    private ?array $definitions = null;

    public function __construct(private readonly ProfileTitleDefinitionRepositoryInterface $repository)
    {
    }

    public function reset(): void
    {
        $this->definitions = null;
    }

    /** Whether a title can be picked at all: written, and not retired. */
    public function isValid(string $key): bool
    {
        $definition = $this->definitions()[$key] ?? null;

        return null !== $definition && !$definition->isRetired();
    }

    /**
     * Whether this account may wear the title.
     *
     * @param list<string> $owned the shop titles the account bought
     */
    public function allowedFor(string $key, bool $isAdmin, bool $isMember, array $owned): bool
    {
        $definition = $this->definitions()[$key] ?? null;

        return null !== $definition && !$definition->isRetired() && $definition->getAccess()->allows($key, $isAdmin, $isMember, $owned);
    }

    /** Why a title was refused, for the profile form. */
    public function refusal(string $key): string
    {
        return match (($this->definitions()[$key] ?? null)?->getAccess() ?? AvatarFrameAccess::Shop) {
            AvatarFrameAccess::Admins => 'Titre réservé aux admins.',
            AvatarFrameAccess::Members => 'Titre réservé aux adhérents.',
            AvatarFrameAccess::Reward => 'Titre à gagner (succès ou quête).',
            default => 'Titre à acheter en boutique.',
        };
    }

    /**
     * The title a profile shows, null for none - a retired title, or an admin-only one while its owner is not admin,
     * shows nothing (the stored key is kept, so the title comes back with the right). Story 41.27: with its rarity,
     * its icon and who may wear it, for the badge and its tooltip.
     *
     * @return array{label: string, rarity: string, icon: string|null, access: string}|null
     */
    public function badge(?string $key, bool $isAdmin): ?array
    {
        $definition = null === $key ? null : ($this->definitions()[$key] ?? null);
        if (null === $definition || $definition->isRetired() || (AvatarFrameAccess::Admins === $definition->getAccess() && !$isAdmin)) {
            return null;
        }

        return [
            'label' => $definition->getLabel(),
            'rarity' => $definition->getRarity()->value,
            'icon' => $definition->getIcon()?->value,
            'access' => $definition->getAccess()->value,
        ];
    }

    /**
     * Story 41.27: the badge of a card row (`title_key`, `roles` JSON), for the surfaces that read raw rows.
     *
     * @param array<string, mixed> $row
     *
     * @return array{label: string, rarity: string, icon: string|null, access: string}|null
     */
    public function badgeForRow(array $row): ?array
    {
        $key = $row['title_key'] ?? null;
        if (!is_string($key)) {
            return null;
        }
        $roles = is_string($row['roles'] ?? null) ? json_decode($row['roles'], true) : null;

        return $this->badge($key, is_array($roles) && in_array('ROLE_ADMIN', $roles, true));
    }

    /**
     * @return list<array{key: string, label: string, access: AvatarFrameAccess, retired: bool, position: int, rarity: string, icon: string|null}>
     */
    public function titles(): array
    {
        return array_values(array_map(static fn (ProfileTitleDefinition $definition): array => [
            'key' => $definition->getKey(),
            'label' => $definition->getLabel(),
            'access' => $definition->getAccess(),
            'retired' => $definition->isRetired(),
            'position' => $definition->getPosition(),
            'rarity' => $definition->getRarity()->value,
            'icon' => $definition->getIcon()?->value,
        ], $this->definitions()));
    }

    /**
     * The titles the shop may sell: in shop access, not retired.
     *
     * @return list<string>
     */
    public function sellableKeys(): array
    {
        $keys = [];
        foreach ($this->definitions() as $key => $definition) {
            if (AvatarFrameAccess::Shop === $definition->getAccess() && !$definition->isRetired()) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /** @return array<string, ProfileTitleDefinition> */
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
