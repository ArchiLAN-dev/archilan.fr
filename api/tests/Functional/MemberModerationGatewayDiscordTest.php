<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Community\Application\Port\MemberModerationGatewayInterface;

/**
 * Story 39.7: the site accounts behind Discord ids, and the linked accounts banned on the site.
 */
final class MemberModerationGatewayDiscordTest extends FunctionalTestCase
{
    public function testAccountsAreFoundByTheirDiscordIdAndTheBannedOnesListed(): void
    {
        $now = new \DateTimeImmutable('2026-09-27T12:00:00+00:00');
        $linked = $this->createUser('linked@example.org');
        $linked->linkDiscord('discord-1', 'linked', $now);
        $linked->ban('Raid', $now);
        $unlinked = $this->createUser('unlinked@example.org');
        $unlinked->ban('Triche', $now);
        $free = $this->createUser('free@example.org');
        $free->linkDiscord('discord-2', 'free', $now);
        $this->entityManager->flush();

        $gateway = self::getContainer()->get(MemberModerationGatewayInterface::class);
        self::assertInstanceOf(MemberModerationGatewayInterface::class, $gateway);

        self::assertSame($linked->getId(), $gateway->userIdForDiscordId('discord-1'));
        self::assertNull($gateway->userIdForDiscordId('discord-404'));

        $banned = $gateway->currentlyBanned();
        self::assertCount(1, $banned, 'an unlinked account has no Discord ban to follow');
        self::assertSame([$linked->getId(), 'discord-1'], [$banned[0]->userId, $banned[0]->discordId]);
    }
}
