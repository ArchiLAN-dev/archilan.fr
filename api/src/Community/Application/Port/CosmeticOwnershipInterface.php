<?php

declare(strict_types=1);

namespace App\Community\Application\Port;

/**
 * The shop cosmetics a member bought (story 41.7). The shop lives in Wallet, which implements this port: Community
 * never depends on Wallet. Story 41.28: the cosmetics won through an achievement or a quest, and where each came
 * from.
 */
interface CosmeticOwnershipInterface
{
    public const string FRAME = 'frame';
    public const string BANNER = 'banner';
    // Story 41.22: a profile title bought in the shop.
    public const string TITLE = 'title';
    // Story 41.23: a colour for the name.
    public const string COLOR = 'color';

    /**
     * @param self::FRAME|self::BANNER|self::TITLE|self::COLOR $type
     *
     * @return list<string> the cosmetic keys of that type the member owns
     */
    public function ownedKeys(string $userId, string $type): array;

    public const string SOURCE_ACHIEVEMENT = 'achievement';
    public const string SOURCE_QUEST = 'quest';
    // Story 30.52: a collection of achievements completed.
    public const string SOURCE_COLLECTION = 'collection';

    /**
     * Story 41.28: gives a member a cosmetic they won.
     *
     * @param self::SOURCE_ACHIEVEMENT|self::SOURCE_QUEST|self::SOURCE_COLLECTION $source
     * @param string                                                              $sourceLabel the achievement's, the quest's or the collection's name
     *
     * @return bool true when newly owned, false when the member already had it
     */
    public function grant(string $userId, string $type, string $key, string $source, string $sourceLabel): bool;

    /**
     * Story 41.28: where each cosmetic the member owns came from.
     *
     * @return array<string, array{source: string, label: string|null, acquiredAt: string}> keyed `{type}:{key}`
     */
    public function origins(string $userId): array;
}
