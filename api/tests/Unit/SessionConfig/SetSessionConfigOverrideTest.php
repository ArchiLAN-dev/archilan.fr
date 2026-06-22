<?php

declare(strict_types=1);

namespace App\Tests\Unit\SessionConfig;

use App\SessionConfig\Application\SetSessionConfigOverride;
use App\SessionConfig\Domain\SessionConfigOverride;
use App\SessionConfig\Domain\SessionConfigOverrideRepositoryInterface;
use PHPUnit\Framework\TestCase;

final class SetSessionConfigOverrideTest extends TestCase
{
    public function testNeverStoresAutoShutdownInAnOverride(): void
    {
        // autoShutdown is locked to the type profile (story 27.9). Even when an admin payload carries
        // it, the write path must strip it so it can never disable a private run's idle shutdown.
        $repo = $this->inMemoryRepo();
        $service = new SetSessionConfigOverride($repo);

        $service->execute('run-1', ['hintCost' => 5, 'autoShutdown' => 0]);

        $stored = $repo->saved['run-1'] ?? null;
        self::assertInstanceOf(SessionConfigOverride::class, $stored);
        self::assertSame(5, $stored->hintCost);
        self::assertNull($stored->autoShutdown);
    }

    public function testAnAutoShutdownOnlyOverrideClearsTheScope(): void
    {
        // After stripping autoShutdown the override is empty, so the scope falls back to the profile.
        $repo = $this->inMemoryRepo();
        $service = new SetSessionConfigOverride($repo);

        $service->execute('run-1', ['autoShutdown' => 0]);

        self::assertArrayNotHasKey('run-1', $repo->saved);
        self::assertContains('run-1', $repo->deleted);
    }

    /**
     * @return SessionConfigOverrideRepositoryInterface&object{saved: array<string, SessionConfigOverride>, deleted: list<string>}
     */
    private function inMemoryRepo(): SessionConfigOverrideRepositoryInterface
    {
        return new class implements SessionConfigOverrideRepositoryInterface {
            /** @var array<string, SessionConfigOverride> */
            public array $saved = [];
            /** @var list<string> */
            public array $deleted = [];

            public function find(string $scopeKey): ?SessionConfigOverride
            {
                return $this->saved[$scopeKey] ?? null;
            }

            public function save(string $scopeKey, SessionConfigOverride $override): void
            {
                $this->saved[$scopeKey] = $override;
            }

            public function delete(string $scopeKey): void
            {
                $this->deleted[] = $scopeKey;
                unset($this->saved[$scopeKey]);
            }
        };
    }
}
