<?php

declare(strict_types=1);

namespace App\Wallet\Application\Query;

interface WalletQueryInterface
{
    public const int PER_PAGE = 25;

    /**
     * A member's wallet (story 41.1 AC5): the gold balance, the balance of each event they hold pelles for
     * (empty ones left out), and one page of the history, newest first.
     *
     * @return array{
     *     gold: int,
     *     events: list<array{eventId: string, eventTitle: string, balance: int}>,
     *     history: array{
     *         items: list<array{id: string, amount: int, kind: string, eventId: string|null, eventTitle: string|null, reason: string, label: string, createdAt: string}>,
     *         page: int,
     *         perPage: int,
     *         total: int
     *     }
     * }
     */
    public function walletOf(string $userId, int $page): array;
}
