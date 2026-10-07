<?php

declare(strict_types=1);

namespace App\Community\Application\Command;

use App\Community\Domain\Entity\ProfileTitleDefinition;
use App\Community\Domain\Enum\AvatarFrameAccess;
use App\Community\Domain\Enum\TitleIcon;
use App\Community\Domain\Enum\TitleRarity;
use App\Community\Domain\Repository\ProfileTitleDefinitionRepositoryInterface;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Exception\ValidationException;
use Psr\Clock\ClockInterface;

/**
 * The admin side of the profile titles (story 41.22): write a title, rename it, change who may wear it, reorder,
 * retire and restore. A title in shop access is then put on sale from the shop page. Story 41.27: its rarity and
 * its icon.
 */
final readonly class ManageProfileTitles
{
    public function __construct(
        private ProfileTitleDefinitionRepositoryInterface $titles,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws ConflictException   when the key is taken
     * @throws ValidationException when the key, the label or the access is invalid
     */
    public function write(string $key, string $label, string $access, string $rarity = 'common', string $icon = ''): void
    {
        if (null !== $this->titles->find($key)) {
            throw new ConflictException('Cette clé est déjà prise.', 'profile_title_key_taken');
        }
        $position = array_reduce($this->titles->all(), static fn (int $max, ProfileTitleDefinition $title): int => max($max, $title->getPosition() + 1), 0);
        try {
            $title = ProfileTitleDefinition::write($key, $label, $this->access($access), $position, $this->clock->now(), $this->rarity($rarity), $this->icon($icon));
        } catch (\DomainException $e) {
            throw $this->invalid($e);
        }
        $this->titles->save($title);
    }

    /**
     * @param string|null $icon null: unchanged, empty: no icon
     *
     * @throws NotFoundException   when the title does not exist
     * @throws ValidationException when the label, the access, the rarity or the icon is invalid
     */
    public function update(string $key, ?string $label, ?string $access, ?int $position, ?string $rarity = null, ?string $icon = null): void
    {
        $title = $this->title($key);
        try {
            $title->update($label, null === $access ? null : $this->access($access), $position);
        } catch (\DomainException $e) {
            throw $this->invalid($e);
        }
        if (null !== $rarity || null !== $icon) {
            $title->restyle(
                null === $rarity ? $title->getRarity() : $this->rarity($rarity),
                null === $icon ? $title->getIcon() : $this->icon($icon),
            );
        }
        $this->titles->save($title);
    }

    /** @throws NotFoundException when the title does not exist */
    public function retire(string $key): void
    {
        $title = $this->title($key);
        $title->retire($this->clock->now());
        $this->titles->save($title);
    }

    /** @throws NotFoundException when the title does not exist */
    public function restore(string $key): void
    {
        $title = $this->title($key);
        $title->restore();
        $this->titles->save($title);
    }

    private function title(string $key): ProfileTitleDefinition
    {
        return $this->titles->find($key) ?? throw new NotFoundException('Titre introuvable.', 'profile_title_not_found');
    }

    private function access(string $access): AvatarFrameAccess
    {
        return AvatarFrameAccess::tryFrom($access) ?? throw new ValidationException('Accès inconnu.', ['access' => ['Accès inconnu.']], 'profile_title_access_invalid');
    }

    private function rarity(string $rarity): TitleRarity
    {
        return TitleRarity::tryFrom($rarity) ?? throw new ValidationException('Rareté inconnue.', ['rarity' => ['Rareté inconnue.']], 'profile_title_rarity_invalid');
    }

    /** Empty: no icon. */
    private function icon(string $icon): ?TitleIcon
    {
        if ('' === $icon) {
            return null;
        }

        return TitleIcon::tryFrom($icon) ?? throw new ValidationException('Icône inconnue.', ['icon' => ['Icône inconnue.']], 'profile_title_icon_invalid');
    }

    private function invalid(\DomainException $e): ValidationException
    {
        return new ValidationException(match ($e->getMessage()) {
            'profile_title_key_invalid' => 'Une clé de 2 à 32 caractères : minuscules, chiffres, tirets.',
            default => sprintf('Un titre de %d à %d caractères.', ProfileTitleDefinition::MIN_LABEL, ProfileTitleDefinition::MAX_LABEL),
        }, [], $e->getMessage());
    }
}
