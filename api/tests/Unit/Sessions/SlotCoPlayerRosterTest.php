<?php

declare(strict_types=1);

namespace App\Tests\Unit\Sessions;

use App\Sessions\Domain\Service\SlotCoPlayerRoster;
use PHPUnit\Framework\TestCase;

/**
 * Story 16.17: the rules that decide who may be added to a slot.
 */
final class SlotCoPlayerRosterTest extends TestCase
{
    public function testKeepsEveryParticipantAsked(): void
    {
        $result = new SlotCoPlayerRoster()->resolve('owner', ['bob', 'carol'], ['owner', 'bob', 'carol']);

        self::assertSame(['bob', 'carol'], $result['userIds']);
        self::assertSame([], $result['errors']);
    }

    /** No limit on the number of co-players: a Minecraft world can hold the whole party. */
    public function testAcceptsAnyNumberOfCoPlayers(): void
    {
        $participants = ['owner'];
        $requested = [];
        for ($i = 0; $i < 25; ++$i) {
            $participants[] = 'p'.$i;
            $requested[] = 'p'.$i;
        }

        $result = new SlotCoPlayerRoster()->resolve('owner', $requested, $participants);

        self::assertCount(25, $result['userIds']);
        self::assertSame([], $result['errors']);
    }

    /** Asking twice is not a mistake worth an error, but it must not store the person twice. */
    public function testCollapsesDuplicatesSilently(): void
    {
        $result = new SlotCoPlayerRoster()->resolve('owner', ['bob', 'bob'], ['owner', 'bob']);

        self::assertSame(['bob'], $result['userIds']);
        self::assertSame([], $result['errors']);
    }

    /** Co-playing a slot one already owns would count its checks twice for the same person. */
    public function testRejectsTheOwner(): void
    {
        $result = new SlotCoPlayerRoster()->resolve('owner', ['owner'], ['owner', 'bob']);

        self::assertSame([], $result['userIds']);
        self::assertSame([SlotCoPlayerRoster::ERROR_OWNER], $result['errors']);
    }

    /** Someone outside the party has no business on one of its slots. */
    public function testRejectsSomeoneWhoIsNotInTheParty(): void
    {
        $result = new SlotCoPlayerRoster()->resolve('owner', ['stranger'], ['owner', 'bob']);

        self::assertSame([], $result['userIds']);
        self::assertSame([SlotCoPlayerRoster::ERROR_NOT_A_PARTICIPANT], $result['errors']);
    }

    public function testAnEmptyRosterIsValid(): void
    {
        $result = new SlotCoPlayerRoster()->resolve('owner', [], ['owner', 'bob']);

        self::assertSame([], $result['userIds']);
        self::assertSame([], $result['errors']);
    }
}
