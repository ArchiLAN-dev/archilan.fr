<?php

declare(strict_types=1);

namespace App\Community\Application\Support;

use App\Community\Domain\Enum\NameColor;
use App\Community\Domain\ValueObject\CosmeticReward;

/**
 * Story 41.28: whether a cosmetic an achievement or a quest unlocks exists, and its name in words - read from the
 * catalogs of each kind (frames, banners, titles, name colours).
 */
final readonly class CosmeticRewardCatalog
{
    public function __construct(
        private AvatarFrameCatalog $frames,
        private ProfileBannerCatalog $banners,
        private ProfileTitleCatalog $titles,
    ) {
    }

    public function exists(CosmeticReward $reward): bool
    {
        return match ($reward->type) {
            CosmeticReward::FRAME => $this->frames->isValid($reward->key),
            CosmeticReward::BANNER => $this->banners->isValid($reward->key),
            CosmeticReward::TITLE => $this->titles->isValid($reward->key),
            default => null !== NameColor::tryFrom($reward->key),
        };
    }

    /** « le titre « Phil Connors » », « la couleur de pseudo Émeraude » - for the notification and the views. */
    public function label(CosmeticReward $reward): string
    {
        $name = match ($reward->type) {
            CosmeticReward::FRAME => $this->labelIn($this->frames->videoFrames(), $reward->key),
            CosmeticReward::BANNER => $this->labelIn($this->banners->banners(), $reward->key),
            CosmeticReward::TITLE => $this->labelIn($this->titles->titles(), $reward->key),
            default => NameColor::tryFrom($reward->key)?->label() ?? $reward->key,
        };

        return sprintf('%s « %s »', self::kind($reward->type), $name);
    }

    public static function kind(string $type): string
    {
        return match ($type) {
            CosmeticReward::FRAME => 'Cadre',
            CosmeticReward::BANNER => 'Bannière',
            CosmeticReward::TITLE => 'Titre',
            default => 'Couleur de pseudo',
        };
    }

    /**
     * @param list<array{key: string, label: string}> $entries
     */
    private function labelIn(array $entries, string $key): string
    {
        foreach ($entries as $entry) {
            if ($entry['key'] === $key) {
                return $entry['label'];
            }
        }

        return $key;
    }
}
