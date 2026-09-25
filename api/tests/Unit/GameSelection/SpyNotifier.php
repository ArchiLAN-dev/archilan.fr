<?php

declare(strict_types=1);

namespace App\Tests\Unit\GameSelection;

use App\Community\Application\Support\Notifier;

/**
 * Records in-app notifications instead of storing them (story 38.2 tests).
 */
final class SpyNotifier implements Notifier
{
    /** @var list<array{recipientId: string, type: string, payload: array<string, mixed>}> */
    public array $sent = [];

    public function notify(string $recipientId, string $type, array $payload): void
    {
        $this->sent[] = ['recipientId' => $recipientId, 'type' => $type, 'payload' => $payload];
    }
}
