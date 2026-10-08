<?php

declare(strict_types=1);

namespace App\Tests\Unit\Community;

use App\Community\Application\Port\CosmeticOwnershipInterface;
use App\Community\Application\Port\PelleRewardInterface;
use App\Community\Application\Support\AvatarFrameCatalog;
use App\Community\Application\Support\CollectionCompletionRewarder;
use App\Community\Application\Support\CosmeticRewardCatalog;
use App\Community\Application\Support\CosmeticRewarder;
use App\Community\Application\Support\Notifier;
use App\Community\Application\Support\ProfileBannerCatalog;
use App\Community\Application\Support\ProfileTitleCatalog;
use App\Community\Domain\Repository\AchievementCollectionRepositoryInterface;
use App\Community\Domain\Repository\AchievementDefinitionRepositoryInterface;
use App\Community\Domain\Repository\AchievementGrantRepositoryInterface;
use App\Community\Domain\Repository\AvatarFrameDefinitionRepositoryInterface;
use App\Community\Domain\Repository\ProfileBannerDefinitionRepositoryInterface;
use App\Community\Domain\Repository\ProfileTitleDefinitionRepositoryInterface;
use Symfony\Component\Clock\MockClock;

/**
 * Story 41.28: the cosmetic rewards for the unit tests of the services that hand them out - empty catalogs, an
 * ownership that owns nothing, a silent notifier.
 */
trait CosmeticRewardDoubles
{
    private function cosmeticCatalog(): CosmeticRewardCatalog
    {
        return new CosmeticRewardCatalog(
            new AvatarFrameCatalog(self::createStub(AvatarFrameDefinitionRepositoryInterface::class)),
            new ProfileBannerCatalog(self::createStub(ProfileBannerDefinitionRepositoryInterface::class)),
            new ProfileTitleCatalog(self::createStub(ProfileTitleDefinitionRepositoryInterface::class)),
        );
    }

    private function cosmeticRewarder(): CosmeticRewarder
    {
        $silent = new class implements Notifier {
            public function notify(string $recipientId, string $type, array $payload): void
            {
            }
        };

        return new CosmeticRewarder(self::createStub(CosmeticOwnershipInterface::class), $this->cosmeticCatalog(), $silent);
    }

    /** Story 30.52: no collection, so nothing to complete. */
    private function collectionRewarder(): CollectionCompletionRewarder
    {
        return new CollectionCompletionRewarder(
            self::createStub(AchievementCollectionRepositoryInterface::class),
            self::createStub(AchievementDefinitionRepositoryInterface::class),
            self::createStub(AchievementGrantRepositoryInterface::class),
            $this->cosmeticRewarder(),
            $this->cosmeticCatalog(),
            self::createStub(PelleRewardInterface::class),
            self::createStub(Notifier::class),
            new MockClock(),
        );
    }
}
