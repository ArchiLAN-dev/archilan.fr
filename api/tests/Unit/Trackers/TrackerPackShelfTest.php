<?php

declare(strict_types=1);

namespace App\Tests\Unit\Trackers;

use App\Trackers\Domain\Entity\TrackerPack;
use App\Trackers\Domain\Service\TrackerPackShelf;
use App\Trackers\Domain\ValueObject\TrackerPackManifest;
use PHPUnit\Framework\TestCase;

final class TrackerPackShelfTest extends TestCase
{
    public function testFirstEnabledPackBecomesTheDefault(): void
    {
        [$a, $b] = $packs = [self::pack('a', 1), self::pack('b', 2)];

        TrackerPackShelf::enable($packs, $b);
        TrackerPackShelf::enable($packs, $a);

        self::assertTrue($b->isDefault());
        self::assertFalse($a->isDefault());
        self::assertTrue($a->isEnabled());
    }

    public function testDisablingTheDefaultHandsItToTheOldestEnabled(): void
    {
        [$a, $b, $c] = $packs = [self::pack('a', 1), self::pack('b', 2), self::pack('c', 3)];
        TrackerPackShelf::enable($packs, $c);
        TrackerPackShelf::enable($packs, $b);
        TrackerPackShelf::enable($packs, $a);

        TrackerPackShelf::disable($packs, $c);

        self::assertFalse($c->isEnabled());
        self::assertFalse($c->isDefault());
        self::assertTrue($a->isDefault());
        self::assertFalse($b->isDefault());
    }

    public function testDisablingTheLastPackLeavesNoDefault(): void
    {
        [$a] = $packs = [self::pack('a', 1)];
        TrackerPackShelf::enable($packs, $a);

        TrackerPackShelf::disable($packs, $a);

        self::assertFalse($a->isDefault());
        self::assertFalse($a->isEnabled());
    }

    public function testDisablingAnotherPackKeepsTheDefault(): void
    {
        [$a, $b] = $packs = [self::pack('a', 1), self::pack('b', 2)];
        TrackerPackShelf::enable($packs, $a);
        TrackerPackShelf::enable($packs, $b);

        TrackerPackShelf::disable($packs, $b);

        self::assertTrue($a->isDefault());
    }

    public function testMakeDefaultMovesTheDefault(): void
    {
        [$a, $b] = $packs = [self::pack('a', 1), self::pack('b', 2)];
        TrackerPackShelf::enable($packs, $a);
        TrackerPackShelf::enable($packs, $b);

        TrackerPackShelf::makeDefault($packs, $b);

        self::assertTrue($b->isDefault());
        self::assertFalse($a->isDefault());
    }

    public function testDisabledPackCannotBeTheDefault(): void
    {
        [, $b] = $packs = [self::pack('a', 1), self::pack('b', 2)];

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('tracker_pack_not_enabled');

        TrackerPackShelf::makeDefault($packs, $b);
    }

    public function testPackWithoutLicenceNeedsAnAcknowledgement(): void
    {
        [$a] = $packs = [self::pack('a', 1, null)];

        try {
            TrackerPackShelf::enable($packs, $a);
            self::fail('A pack without licence should not be enabled.');
        } catch (\DomainException $e) {
            self::assertSame('tracker_pack_license_unacknowledged', $e->getMessage());
        }

        $a->acknowledgeLicense(true);
        TrackerPackShelf::enable($packs, $a);
        self::assertTrue($a->isEnabled());
    }

    public function testAcknowledgementCannotBeWithdrawnFromAnEnabledPackWithoutLicence(): void
    {
        [$a] = $packs = [self::pack('a', 1, null)];
        $a->acknowledgeLicense(true);
        TrackerPackShelf::enable($packs, $a);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('tracker_pack_license_unacknowledged');

        $a->acknowledgeLicense(false);
    }

    public function testDefaultVariantMustExist(): void
    {
        $a = self::pack('a', 1);
        $a->chooseDefaultVariant('itemsonly', ['standard', 'itemsonly']);
        self::assertSame('itemsonly', $a->getDefaultVariantUid());

        $a->chooseDefaultVariant(null, ['standard']);
        self::assertNull($a->getDefaultVariantUid());

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('tracker_pack_variant_unknown');
        $a->chooseDefaultVariant('mapsonly', ['standard']);
    }

    private static function pack(string $id, int $day, ?string $license = 'MIT'): TrackerPack
    {
        return TrackerPack::import(
            $id,
            'game',
            'https://github.com/o/'.$id,
            TrackerPackManifest::fromArray(['package_uid' => 'uid-'.$id, 'name' => 'Pack '.$id]),
            $license,
            'v-'.$id,
            'admin',
            new \DateTimeImmutable(sprintf('2026-11-%02dT10:00:00+00:00', $day)),
        );
    }
}
