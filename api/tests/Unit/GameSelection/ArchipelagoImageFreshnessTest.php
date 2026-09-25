<?php

declare(strict_types=1);

namespace App\Tests\Unit\GameSelection;

use App\GameSelection\Domain\Service\ArchipelagoImageFreshness;
use PHPUnit\Framework\TestCase;

/**
 * Story 38.8: was a verdict produced on the Archipelago image that runs now?
 */
final class ArchipelagoImageFreshnessTest extends TestCase
{
    private const string REF = 'ghcr.io/archilan-dev/archipelago:0.16.1';

    public function testTheSameImageIdIsCurrent(): void
    {
        self::assertTrue(ArchipelagoImageFreshness::isCurrent(self::REF, 'sha256:a', self::REF, 'sha256:a'));
    }

    public function testARePushedTagIsNotCurrent(): void
    {
        // Same reference, another image: `archipelago:latest` locally, or a tag pushed again.
        self::assertFalse(ArchipelagoImageFreshness::isCurrent(self::REF, 'sha256:a', self::REF, 'sha256:b'));
    }

    public function testAnotherReferenceIsNotCurrent(): void
    {
        self::assertFalse(ArchipelagoImageFreshness::isCurrent('ghcr.io/archilan-dev/archipelago:0.16.0', null, self::REF, 'sha256:b'));
    }

    public function testWithoutAnIdOnEitherSideTheReferencesDecide(): void
    {
        self::assertTrue(ArchipelagoImageFreshness::isCurrent(self::REF, null, self::REF, 'sha256:a'));
        self::assertTrue(ArchipelagoImageFreshness::isCurrent(self::REF, 'sha256:a', self::REF, null));
    }

    public function testAVerdictWithoutImageCountsAsAnOlderOne(): void
    {
        self::assertFalse(ArchipelagoImageFreshness::isCurrent(null, null, self::REF, 'sha256:a'));
        self::assertFalse(ArchipelagoImageFreshness::isCurrent('', '', self::REF, 'sha256:a'));
    }
}
