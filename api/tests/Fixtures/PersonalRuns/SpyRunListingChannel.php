<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\PersonalRuns;

use App\PersonalRuns\Application\Exception\RunListingDeliveryException;
use App\PersonalRuns\Application\Port\RunListingChannelInterface;
use App\PersonalRuns\Application\Support\RunListingAnnouncement;

/**
 * Story 43.27: a Discord channel for the tests. Keeps the messages it holds (`m1`, `m2`...), records every call, and
 * can be switched off, made to fail, or have a message deleted behind its back.
 */
final class SpyRunListingChannel implements RunListingChannelInterface
{
    public bool $enabled = true;
    public bool $failing = false;
    /** @var array<string, RunListingAnnouncement> message id => what it says */
    public array $messages = [];
    /** @var list<array{call: string, messageId: string|null, mention: bool}> */
    public array $calls = [];
    private int $next = 0;

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function post(RunListingAnnouncement $announcement, bool $mention): array
    {
        $this->fail();
        $id = 'm'.++$this->next;
        $this->messages[$id] = $announcement;
        $this->calls[] = ['call' => 'post', 'messageId' => $id, 'mention' => $mention];

        return ['messageId' => $id, 'mentioned' => $mention];
    }

    public function edit(string $messageId, RunListingAnnouncement $announcement): bool
    {
        $this->fail();
        $this->calls[] = ['call' => 'edit', 'messageId' => $messageId, 'mention' => false];
        if (!isset($this->messages[$messageId])) {
            return false;
        }
        $this->messages[$messageId] = $announcement;

        return true;
    }

    public function delete(string $messageId): void
    {
        $this->fail();
        $this->calls[] = ['call' => 'delete', 'messageId' => $messageId, 'mention' => false];
        unset($this->messages[$messageId]);
    }

    /** @return list<string> the calls made, as "post", "edit" or "delete" */
    public function callNames(): array
    {
        return array_column($this->calls, 'call');
    }

    private function fail(): void
    {
        if ($this->failing) {
            throw new RunListingDeliveryException('Discord answered HTTP 503.');
        }
    }
}
