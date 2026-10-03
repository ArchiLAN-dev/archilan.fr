<?php

declare(strict_types=1);

namespace App\Tests\Unit\Wallet;

use App\Wallet\Domain\Entity\PelleMovement;
use App\Wallet\Domain\Enum\PelleKind;
use App\Wallet\Domain\Enum\PelleReason;
use App\Wallet\Domain\Exception\InvalidPelleMovementException;
use PHPUnit\Framework\TestCase;

/**
 * Story 41.1. A line of the pelles ledger: a gain or a spend, never zero, gold or event pelles.
 */
final class PelleMovementTest extends TestCase
{
    private const string AT = '2026-10-03 12:00:00+00:00';

    public function testAGoldCreditCarriesWhatTheHistoryShows(): void
    {
        $movement = PelleMovement::record('user-1', 50, PelleKind::Gold, null, PelleReason::AdminCredit, 'Happening de la LAN', 'admin-1', null, new \DateTimeImmutable(self::AT));

        self::assertSame('user-1', $movement->getUserId());
        self::assertSame(50, $movement->getAmount());
        self::assertSame(PelleKind::Gold, $movement->getKind());
        self::assertNull($movement->getEventId());
        self::assertSame(PelleReason::AdminCredit, $movement->getReason());
        self::assertSame('Happening de la LAN', $movement->getLabel());
        self::assertSame('admin-1', $movement->getAuthorId());
        self::assertFalse($movement->isSpend());
        self::assertSame(32, \strlen($movement->getId()));
    }

    public function testADebitIsASpend(): void
    {
        $movement = PelleMovement::record('user-1', -20, PelleKind::Gold, null, PelleReason::AdminDebit, 'Correction', 'admin-1', null, new \DateTimeImmutable(self::AT));

        self::assertTrue($movement->isSpend());
    }

    public function testEventPellesBelongToOneEvent(): void
    {
        $movement = PelleMovement::record('user-1', 30, PelleKind::Event, 'event-1', PelleReason::AdminCredit, 'Happening', 'admin-1', 'happening-1-user-1', new \DateTimeImmutable(self::AT));

        self::assertSame('event-1', $movement->getEventId());
        self::assertSame('happening-1-user-1', $movement->getUniqueKey());
    }

    /**
     * @return iterable<string, array{int, PelleKind, ?string, string}>
     */
    public static function invalidMovements(): iterable
    {
        yield 'zero' => [0, PelleKind::Gold, null, 'Libellé'];
        yield 'event pelles without event' => [10, PelleKind::Event, null, 'Libellé'];
        yield 'gold pelles tied to an event' => [10, PelleKind::Gold, 'event-1', 'Libellé'];
        yield 'blank label' => [10, PelleKind::Gold, null, '   '];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidMovements')]
    public function testAnInvalidMovementIsRefused(int $amount, PelleKind $kind, ?string $eventId, string $label): void
    {
        $this->expectException(InvalidPelleMovementException::class);

        PelleMovement::record('user-1', $amount, $kind, $eventId, PelleReason::AdminCredit, $label, 'admin-1', null, new \DateTimeImmutable(self::AT));
    }
}
