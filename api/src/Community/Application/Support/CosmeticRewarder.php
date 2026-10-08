<?php

declare(strict_types=1);

namespace App\Community\Application\Support;

use App\Community\Application\Port\CosmeticOwnershipInterface;
use App\Community\Domain\Entity\Notification;
use App\Community\Domain\ValueObject\CosmeticReward;

/**
 * Story 41.28: gives a member the cosmetic an achievement or a quest unlocks, once, and tells them. A cosmetic they
 * already owned (bought, or won elsewhere) is neither given again nor announced.
 */
final readonly class CosmeticRewarder
{
    public function __construct(
        private CosmeticOwnershipInterface $ownership,
        private CosmeticRewardCatalog $catalog,
        private Notifier $notifier,
    ) {
    }

    /**
     * @param CosmeticOwnershipInterface::SOURCE_ACHIEVEMENT|CosmeticOwnershipInterface::SOURCE_QUEST|CosmeticOwnershipInterface::SOURCE_COLLECTION $source
     *
     * @return bool true when newly given
     */
    public function reward(string $userId, CosmeticReward $reward, string $source, string $sourceLabel, bool $notify = true): bool
    {
        if (!$this->ownership->grant($userId, $reward->type, $reward->key, $source, $sourceLabel)) {
            return false;
        }
        if ($notify) {
            $this->notifier->notify($userId, Notification::TYPE_COSMETIC_UNLOCKED, [
                'type' => $reward->type,
                'key' => $reward->key,
                'label' => $this->catalog->label($reward),
                'source' => $source,
                'sourceLabel' => $sourceLabel,
            ]);
        }

        return true;
    }
}
