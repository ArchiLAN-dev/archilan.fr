<?php

declare(strict_types=1);

namespace App\Tests\Unit\PersonalRuns;

use App\PersonalRuns\Domain\YamlTemplate;
use PHPUnit\Framework\TestCase;

final class YamlTemplateTest extends TestCase
{
    public function testCreateSetsOwnerGameNameAndYaml(): void
    {
        $now = new \DateTimeImmutable('2026-06-17T10:00:00+00:00');
        $template = YamlTemplate::create('user-1', 'game-1', 'Preset', "name: A\n", $now);

        self::assertNotSame('', $template->getId());
        self::assertTrue($template->isOwnedBy('user-1'));
        self::assertFalse($template->isOwnedBy('user-2'));
        self::assertSame('game-1', $template->getGameId());
        self::assertSame('Preset', $template->getName());
        self::assertSame("name: A\n", $template->getYaml());
        self::assertEquals($now, $template->getCreatedAt());
        self::assertEquals($now, $template->getUpdatedAt());
    }

    public function testRenameUpdatesNameAndTimestamp(): void
    {
        $created = new \DateTimeImmutable('2026-06-17T10:00:00+00:00');
        $template = YamlTemplate::create('user-1', 'game-1', 'Old', "name: A\n", $created);

        $renamedAt = new \DateTimeImmutable('2026-06-18T10:00:00+00:00');
        $template->rename('New', $renamedAt);

        self::assertSame('New', $template->getName());
        self::assertEquals($renamedAt, $template->getUpdatedAt());
        self::assertEquals($created, $template->getCreatedAt());
    }

    public function testUpdateYamlUpdatesContentAndTimestamp(): void
    {
        $created = new \DateTimeImmutable('2026-06-17T10:00:00+00:00');
        $template = YamlTemplate::create('user-1', 'game-1', 'Preset', "name: A\n", $created);

        $updatedAt = new \DateTimeImmutable('2026-06-18T10:00:00+00:00');
        $template->updateYaml("name: B\n", $updatedAt);

        self::assertSame("name: B\n", $template->getYaml());
        self::assertEquals($updatedAt, $template->getUpdatedAt());
    }
}
