<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Community\Application\Port\MemberModerationGatewayInterface;

/**
 * Story 39.6: the members still suspended on the site, as the daily Discord timeout extension reads them.
 */
final class MemberModerationGatewaySuspendedTest extends FunctionalTestCase
{
    public function testOnlyTheMembersStillSuspendedAreListed(): void
    {
        $now = new \DateTimeImmutable('2026-09-28T04:00:00+00:00');
        $running = $this->createUser('running@example.org');
        $running->linkDiscord('discord-1', 'running', $now);
        $running->suspendUntil(new \DateTimeImmutable('2026-12-01T00:00:00+00:00'), 'Récidive', $now);
        $over = $this->createUser('over@example.org');
        $over->suspendUntil(new \DateTimeImmutable('2026-09-01T00:00:00+00:00'), 'Vieux', $now);
        $banned = $this->createUser('banned@example.org');
        $banned->ban('Triche', $now);
        $this->createUser('fine@example.org');
        $this->entityManager->flush();

        $gateway = self::getContainer()->get(MemberModerationGatewayInterface::class);
        self::assertInstanceOf(MemberModerationGatewayInterface::class, $gateway);
        $suspended = $gateway->currentlySuspended($now);

        self::assertCount(1, $suspended);
        self::assertSame($running->getId(), $suspended[0]->userId);
        self::assertSame('discord-1', $suspended[0]->discordId);
        self::assertSame('2026-12-01T00:00:00+00:00', $suspended[0]->suspendedUntil);
        self::assertSame('Récidive', $suspended[0]->reason);
    }
}
