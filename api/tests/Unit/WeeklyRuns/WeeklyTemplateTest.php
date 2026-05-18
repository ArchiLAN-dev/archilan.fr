<?php

declare(strict_types=1);

namespace App\Tests\Unit\WeeklyRuns;

use App\WeeklyRuns\Domain\WeeklyTemplate;
use PHPUnit\Framework\TestCase;

final class WeeklyTemplateTest extends TestCase
{
    public function testDeactivateSetsIsActiveToFalse(): void
    {
        $template = $this->makeTemplate(isActive: true);
        $now = new \DateTimeImmutable('2026-05-20T12:00:00+00:00');

        $template->deactivate($now);

        self::assertFalse($template->isActive());
    }

    public function testDeactivateUpdatesUpdatedAt(): void
    {
        $template = $this->makeTemplate(isActive: true);
        $now = new \DateTimeImmutable('2026-05-20T12:00:00+00:00');

        $template->deactivate($now);

        self::assertEquals($now, $template->getUpdatedAt());
    }

    public function testDeactivateIsIdempotent(): void
    {
        $template = $this->makeTemplate(isActive: false);
        $now = new \DateTimeImmutable('2026-05-20T12:00:00+00:00');

        $template->deactivate($now);

        self::assertFalse($template->isActive());
    }

    public function testApplyChangesUpdatesOnlyProvidedKeys(): void
    {
        $template = $this->makeTemplate();
        $now = new \DateTimeImmutable('2026-05-20T12:00:00+00:00');

        $template->applyChanges(['name' => 'New Name'], $now);

        self::assertSame('New Name', $template->getName());
        self::assertNull($template->getMaxAttempts());
        self::assertEquals($now, $template->getUpdatedAt());
    }

    public function testApplyChangesCanSetNameToNull(): void
    {
        $template = $this->makeTemplate();
        $now = new \DateTimeImmutable('2026-05-20T12:00:00+00:00');

        $template->applyChanges(['name' => null], $now);

        self::assertNull($template->getName());
    }

    public function testApplyChangesUpdatesMultipleFields(): void
    {
        $template = $this->makeTemplate();
        $now = new \DateTimeImmutable('2026-05-20T12:00:00+00:00');

        $template->applyChanges(['name' => 'Renamed', 'maxAttempts' => 5, 'isActive' => false], $now);

        self::assertSame('Renamed', $template->getName());
        self::assertSame(5, $template->getMaxAttempts());
        self::assertFalse($template->isActive());
    }

    private function makeTemplate(bool $isActive = true): WeeklyTemplate
    {
        $now = new \DateTimeImmutable('2026-05-17T00:00:00+00:00');

        return new WeeklyTemplate(
            id: 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            gameId: 'ffffffff-1111-2222-3333-444444444444',
            yamlConfig: "name: ArchiLAN\n",
            name: 'Test Template',
            maxAttempts: null,
            isActive: $isActive,
            createdAt: $now,
            updatedAt: $now,
        );
    }
}
