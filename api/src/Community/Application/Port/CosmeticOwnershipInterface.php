<?php

declare(strict_types=1);

namespace App\Community\Application\Port;

/**
 * The shop cosmetics a member bought (story 41.7). The shop lives in Wallet, which implements this port: Community
 * never depends on Wallet.
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
}
