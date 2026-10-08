<?php

declare(strict_types=1);

namespace App\Wallet\Application\Query;

use App\Community\Application\Port\CosmeticOwnershipInterface;
use App\Community\Application\Support\AvatarFrameCatalog;
use App\Community\Application\Support\ProfileBannerCatalog;
use App\Community\Application\Support\ProfileTitleCatalog;
use App\Community\Domain\Entity\AchievementDefinition;
use App\Community\Domain\Enum\AvatarFrameAccess;
use App\Community\Domain\Enum\NameColor;
use App\Community\Domain\Repository\AchievementCollectionRepositoryInterface;
use App\Community\Domain\Repository\AchievementDefinitionRepositoryInterface;
use App\Membership\Application\Query\ActiveMembershipQueryInterface;
use App\Wallet\Domain\Entity\QuestDefinition;
use App\Wallet\Domain\Entity\ShopItem;
use App\Wallet\Domain\Repository\QuestRepositoryInterface;
use App\Wallet\Domain\Repository\ShopRepositoryInterface;
use Psr\Clock\ClockInterface;

/**
 * « Ma collection » (story 41.29): every cosmetic of the site - titles, name colours, frames, banners - with what the
 * member owns and where it came from, what they may already wear, and for the rest how to get it (the shop and its
 * price, the achievements and quests that unlock it, the membership).
 *
 * @phpstan-type Unlock array{kind: string, label: string, detail: string|null, price: int|null}
 * @phpstan-type Item array{type: string, key: string, label: string, access: string, rarity: string|null, icon: string|null, status: string, origin: array{source: string, label: string|null, acquiredAt: string}|null, unlock: list<Unlock>}
 */
final readonly class MyCollection
{
    public const string OWNED = 'owned';
    public const string AVAILABLE = 'available';
    public const string LOCKED = 'locked';

    public function __construct(
        private ShopRepositoryInterface $shop,
        private QuestRepositoryInterface $quests,
        private AchievementDefinitionRepositoryInterface $achievements,
        private AchievementCollectionRepositoryInterface $collections,
        private AvatarFrameCatalog $frames,
        private ProfileBannerCatalog $banners,
        private ProfileTitleCatalog $titles,
        private ActiveMembershipQueryInterface $memberships,
        private CosmeticOwnershipInterface $ownership,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array{items: list<Item>, owned: int, total: int}
     */
    public function of(string $userId, bool $isAdmin): array
    {
        $isMember = $this->memberships->hasActiveMembership($userId);
        $origins = $this->ownership->origins($userId);
        $now = $this->clock->now();
        $onSale = [];
        foreach ($this->shop->allItems() as $item) {
            if ($item->isOnSale($now)) {
                $onSale[$item->getType().':'.$item->getCosmeticKey()] = $item;
            }
        }
        $rewards = $this->rewards();

        $entries = [];
        foreach ($this->titles->titles() as $title) {
            if (!$title['retired']) {
                $entries[] = ['title', $title['key'], $title['label'], $title['access'], $title['rarity'], $title['icon']];
            }
        }
        foreach (NameColor::cases() as $color) {
            $entries[] = ['color', $color->value, $color->label(), AvatarFrameAccess::Shop, null, null];
        }
        foreach ($this->frames->videoFrames() as $frame) {
            if (!$frame['retired']) {
                $entries[] = ['frame', $frame['key'], $frame['label'], $frame['access'], null, null];
            }
        }
        foreach ($this->banners->banners() as $banner) {
            if (!$banner['retired']) {
                $entries[] = ['banner', $banner['key'], $banner['label'], $banner['access'], null, null];
            }
        }

        $items = [];
        foreach ($entries as [$type, $key, $label, $access, $rarity, $icon]) {
            $id = $type.':'.$key;
            $origin = $origins[$id] ?? null;
            $wearable = AvatarFrameAccess::Free === $access
                || (AvatarFrameAccess::Members === $access && ($isMember || $isAdmin))
                || (AvatarFrameAccess::Admins === $access && $isAdmin);
            $items[] = [
                'type' => $type,
                'key' => $key,
                'label' => $label,
                'access' => $access->value,
                'rarity' => $rarity,
                'icon' => $icon,
                'status' => null !== $origin ? self::OWNED : ($wearable ? self::AVAILABLE : self::LOCKED),
                'origin' => $origin,
                'unlock' => null !== $origin || $wearable ? [] : $this->unlock($access, $onSale[$id] ?? null, $rewards[$id] ?? [], $now),
            ];
        }

        return [
            'items' => $items,
            'owned' => \count(array_filter($items, static fn (array $item): bool => self::OWNED === $item['status'])),
            'total' => \count($items),
        ];
    }

    /**
     * How to get a cosmetic the member has not: the achievements and quests that unlock it first, then the shop or
     * the status it is kept for.
     *
     * @param list<array{kind: string, label: string, detail: string|null, price: int|null}> $rewards
     *
     * @return list<array{kind: string, label: string, detail: string|null, price: int|null}>
     */
    private function unlock(AvatarFrameAccess $access, ?ShopItem $item, array $rewards, \DateTimeImmutable $now): array
    {
        $ways = $rewards;
        if (null !== $item) {
            $ways[] = ['kind' => 'shop', 'label' => 'En boutique', 'detail' => null, 'price' => $item->priceAt($now)];
        } elseif (AvatarFrameAccess::Shop === $access) {
            $ways[] = ['kind' => 'shop', 'label' => 'Pas encore en vente', 'detail' => null, 'price' => null];
        }
        if (AvatarFrameAccess::Members === $access) {
            $ways[] = ['kind' => 'members', 'label' => 'Réservé aux adhérents', 'detail' => null, 'price' => null];
        }
        if (AvatarFrameAccess::Admins === $access) {
            $ways[] = ['kind' => 'admins', 'label' => 'Réservé aux admins', 'detail' => null, 'price' => null];
        }
        if ([] === $ways && AvatarFrameAccess::Reward === $access) {
            $ways[] = ['kind' => 'reward', 'label' => 'À gagner : bientôt un succès ou une quête', 'detail' => null, 'price' => null];
        }

        return $ways;
    }

    /**
     * The active achievements, the collections and the quests still out that unlock each cosmetic.
     *
     * @return array<string, list<array{kind: string, label: string, detail: string|null, price: int|null}>> keyed `{type}:{key}`
     */
    private function rewards(): array
    {
        $rewards = [];
        foreach ($this->achievements->allActive() as $achievement) {
            $reward = $achievement->getReward();
            if (null !== $reward) {
                $rewards[$reward->type.':'.$reward->key][] = $this->fromAchievement($achievement);
            }
        }
        // Story 30.52: a completed collection; a secret one keeps its name.
        foreach ($this->collections->all() as $collection) {
            $cosmetic = $collection->getCosmeticReward();
            if (null !== $cosmetic) {
                $rewards[$cosmetic->type.':'.$cosmetic->key][] = $collection->isSecret()
                    ? ['kind' => 'achievement', 'label' => 'Une collection secrète de succès', 'detail' => 'Complète-la pour le gagner.', 'price' => null]
                    : ['kind' => 'achievement', 'label' => sprintf('Collection « %s »', $collection->getName()), 'detail' => 'Débloque tous les succès de la collection.', 'price' => null];
            }
        }
        foreach ($this->quests->allQuests() as $quest) {
            $cosmetic = $quest->getCosmetic();
            if (null !== $cosmetic && !$quest->isRetired()) {
                $rewards[$cosmetic['type'].':'.$cosmetic['key']][] = $this->fromQuest($quest);
            }
        }

        return $rewards;
    }

    /** @return array{kind: string, label: string, detail: string|null, price: int|null} */
    private function fromAchievement(AchievementDefinition $achievement): array
    {
        $description = trim($achievement->getDescription());

        return ['kind' => 'achievement', 'label' => sprintf('Succès « %s »', $achievement->getName()), 'detail' => '' === $description ? null : $description, 'price' => null];
    }

    /** @return array{kind: string, label: string, detail: string|null, price: int|null} */
    private function fromQuest(QuestDefinition $quest): array
    {
        return [
            'kind' => 'quest',
            'label' => sprintf('Quête « %s »', $quest->getTitle()),
            'detail' => $quest->isInDraw() ? 'Une quête de la semaine, tirée au hasard.' : 'Une quête spéciale, épinglée par l\'équipe.',
            'price' => null,
        ];
    }
}
