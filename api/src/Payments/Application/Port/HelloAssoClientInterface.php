<?php

declare(strict_types=1);

namespace App\Payments\Application\Port;

use App\Payments\Application\Support\HelloAssoConfig;

interface HelloAssoClientInterface
{
    public function getConfig(): HelloAssoConfig;

    public function getAccessToken(): string;

    /**
     * @return array{orderId: int, amountCents: int, payerEmail: string|null, payerFirstName: string|null, payerLastName: string|null, paidAt: \DateTimeImmutable|null}|null
     */
    public function fetchOrder(int $orderId, string $accessToken): ?array;

    /**
     * @return list<array{orderId: int, status: string, amountCents: int, payerEmail: string|null, payerFirstName: string|null, payerLastName: string|null, paidAt: \DateTimeImmutable|null}>
     */
    public function fetchFormItems(string $formType, string $formSlug, string $accessToken): array;
}
