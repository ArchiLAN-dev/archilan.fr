<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity;

use App\Identity\Application\DiscordResyncAllUsersInterface;
use App\Identity\Presentation\Command\ResyncDiscordRolesCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ResyncDiscordRolesCommandTest extends TestCase
{
    public function testDispatchesMessagesAndOutputsCount(): void
    {
        $service = $this->createMock(DiscordResyncAllUsersInterface::class);
        $service->expects($this->once())->method('run')->with(false)->willReturn(5);

        $tester = new CommandTester(new ResyncDiscordRolesCommand($service));
        $tester->execute([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Dispatched 5 sync messages.', $tester->getDisplay());
    }

    public function testDryRunDoesNotDispatchAndOutputsCountWithPrefix(): void
    {
        $service = $this->createMock(DiscordResyncAllUsersInterface::class);
        $service->expects($this->once())->method('run')->with(true)->willReturn(3);

        $tester = new CommandTester(new ResyncDiscordRolesCommand($service));
        $tester->execute(['--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('[DRY-RUN] Would dispatch 3 sync messages.', $tester->getDisplay());
    }

    public function testNoLinkedAccountsOutputsSpecificMessage(): void
    {
        $service = $this->createStub(DiscordResyncAllUsersInterface::class);
        $service->method('run')->willReturn(0);

        $tester = new CommandTester(new ResyncDiscordRolesCommand($service));
        $tester->execute([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('No linked Discord accounts found.', $tester->getDisplay());
    }

    public function testNoLinkedAccountsWithDryRunAlsoOutputsNoAccountsMessage(): void
    {
        $service = $this->createStub(DiscordResyncAllUsersInterface::class);
        $service->method('run')->willReturn(0);

        $tester = new CommandTester(new ResyncDiscordRolesCommand($service));
        $tester->execute(['--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('No linked Discord accounts found.', $tester->getDisplay());
    }
}
