<?php

declare(strict_types=1);

namespace App\Community\Application\Support;

use App\Community\Application\Port\CosmeticOwnershipInterface;
use App\Community\Application\Port\PelleRewardInterface;
use App\Community\Domain\Entity\AchievementCollectionCompletion;
use App\Community\Domain\Entity\Notification;
use App\Community\Domain\Repository\AchievementCollectionRepositoryInterface;
use App\Community\Domain\Repository\AchievementDefinitionRepositoryInterface;
use App\Community\Domain\Repository\AchievementGrantRepositoryInterface;
use Psr\Clock\ClockInterface;

/**
 * Story 30.52: after a member unlocked achievements, the collections they now complete (every active achievement
 * of it unlocked). Each completion is written once and gives the collection's reward once: its cosmetic (origin
 * « collection ») and its pelles (one keyed movement), told in a single « Collection complète » notification.
 */
final readonly class CollectionCompletionRewarder
{
    public function __construct(
        private AchievementCollectionRepositoryInterface $collections,
        private AchievementDefinitionRepositoryInterface $definitions,
        private AchievementGrantRepositoryInterface $grants,
        private CosmeticRewarder $cosmetics,
        private CosmeticRewardCatalog $catalog,
        private PelleRewardInterface $pelles,
        private Notifier $notifier,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return int the number of collections newly completed
     */
    public function settle(string $userId, bool $notify = true): int
    {
        $collections = $this->collections->all();
        if ([] === $collections) {
            return 0;
        }
        $completed = array_flip($this->collections->completedIds($userId));
        $granted = array_flip($this->grants->grantedKeys($userId));
        $keysByCollection = [];
        foreach ($this->definitions->allActive() as $definition) {
            $collectionId = $definition->getCollectionId();
            if (null !== $collectionId) {
                $keysByCollection[$collectionId][] = $definition->getKey();
            }
        }

        $now = $this->clock->now();
        $settled = 0;
        foreach ($collections as $collection) {
            $id = $collection->getId();
            $keys = $keysByCollection[$id] ?? [];
            // An empty collection is never complete.
            if (isset($completed[$id]) || [] === $keys || [] !== array_diff_key(array_flip($keys), $granted)) {
                continue;
            }
            $this->collections->saveCompletion(AchievementCollectionCompletion::complete($userId, $id, $now));

            $cosmetic = $collection->getCosmeticReward();
            // The collection's notification says it all: the cosmetic is given without its own.
            $cosmeticLabel = null !== $cosmetic && $this->cosmetics->reward($userId, $cosmetic, CosmeticOwnershipInterface::SOURCE_COLLECTION, $collection->getName(), false)
                ? $this->catalog->label($cosmetic)
                : null;
            $pelles = $collection->getRewardPelles();
            $pellesGiven = $pelles > 0 && $this->pelles->credit($userId, $pelles, sprintf('Collection complète : %s', $collection->getName()), sprintf('collection:%s:%s', $id, $userId)) ? $pelles : 0;

            if ($notify) {
                $this->notifier->notify($userId, Notification::TYPE_COLLECTION_COMPLETED, [
                    'collectionId' => $id,
                    'name' => $collection->getName(),
                    'pelles' => $pellesGiven,
                    'cosmetic' => $cosmeticLabel,
                ]);
            }
            ++$settled;
        }

        return $settled;
    }
}
