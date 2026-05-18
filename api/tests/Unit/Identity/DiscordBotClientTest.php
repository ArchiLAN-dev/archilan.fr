<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity;

use App\Identity\Infrastructure\DiscordBotClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class DiscordBotClientTest extends TestCase
{
    public function testAssignRoleAcceptsNoContentResponse(): void
    {
        $client = new DiscordBotClient(
            'bot-token',
            new MockHttpClient(new MockResponse('', ['http_code' => 204])),
        );

        $client->assignRole('guild-123', 'discord-456', 'role-member');

        self::addToAssertionCount(1);
    }

    public function testAssignRoleThrowsWhenDiscordDoesNotReturnNoContent(): void
    {
        $client = new DiscordBotClient(
            'bot-token',
            new MockHttpClient(new MockResponse('Forbidden', ['http_code' => 403])),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('expected 204, got 403');

        $client->assignRole('guild-123', 'discord-456', 'role-member');
    }

    public function testRemoveRoleThrowsWhenDiscordDoesNotReturnNoContent(): void
    {
        $client = new DiscordBotClient(
            'bot-token',
            new MockHttpClient(new MockResponse('Not found', ['http_code' => 404])),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('expected 204, got 404');

        $client->removeRole('guild-123', 'discord-456', 'role-member');
    }
}
