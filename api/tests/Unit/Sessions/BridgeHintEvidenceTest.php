<?php

declare(strict_types=1);

namespace App\Tests\Unit\Sessions;

use App\Sessions\Infrastructure\Bridge\BridgeHintEvidence;
use Archilan\BridgeClient\Slots\Response\Hint;
use PHPUnit\Framework\TestCase;

/**
 * Story 41.3: telling, from a slot's hint list, whether a bought hint was actually given.
 */
final class BridgeHintEvidenceTest extends TestCase
{
    public function testAnItemHintIsTheReceiversHintForThatItem(): void
    {
        $hints = [
            $this->hint(receiving: 1, finding: 2, location: 10, item: 'Grappin', found: false),
            $this->hint(receiving: 2, finding: 1, location: 11, item: 'Bombe', found: false),
        ];

        self::assertSame('open', BridgeHintEvidence::forItem($hints, 1, 'grappin'), 'item names match regardless of case');
        self::assertSame('none', BridgeHintEvidence::forItem($hints, 1, 'Bombe'), 'a hint for another receiver');
    }

    public function testALocationHintIsTheFindersHintForThatLocation(): void
    {
        $hints = [$this->hint(receiving: 2, finding: 1, location: 11, item: 'Bombe', found: false)];

        self::assertSame('open', BridgeHintEvidence::forLocation($hints, 1, 11));
        self::assertSame('none', BridgeHintEvidence::forLocation($hints, 1, 12));
        self::assertSame('none', BridgeHintEvidence::forLocation($hints, 2, 11));
    }

    public function testHintsAllFoundLeaveNothingToLearn(): void
    {
        $hints = [
            $this->hint(receiving: 1, finding: 2, location: 10, item: 'Clé', found: true),
            $this->hint(receiving: 1, finding: 3, location: 20, item: 'Clé', found: true),
        ];

        self::assertSame('found', BridgeHintEvidence::forItem($hints, 1, 'Clé'));
        $hints[] = $this->hint(receiving: 1, finding: 3, location: 21, item: 'Clé', found: false);
        self::assertSame('open', BridgeHintEvidence::forItem($hints, 1, 'Clé'), 'one copy still out there');
    }

    private function hint(int $receiving, int $finding, int $location, string $item, bool $found): Hint
    {
        return Hint::fromArray([
            'receivingPlayer' => $receiving,
            'findingPlayer' => $finding,
            'locationId' => $location,
            'itemName' => $item,
            'status' => $found ? 40 : 0,
        ]);
    }
}
