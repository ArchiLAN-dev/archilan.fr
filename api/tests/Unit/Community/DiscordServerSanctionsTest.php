<?php

declare(strict_types=1);

namespace App\Tests\Unit\Community;

use App\Community\Application\Exception\DiscordServerSanctionException;
use App\Community\Application\Port\DiscordServerSanctionsInterface;
use App\Community\Infrastructure\Adapter\DiscordServerSanctions;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Story 39.5: the site's bans applied on the Discord server by the project's bot.
 */
final class DiscordServerSanctionsTest extends TestCase
{
    private const string GUILD = '1300000000000000001';

    /** @var list<array{method: string, url: string, body: array<mixed>|null, auditReason: string|null}> */
    private array $requests = [];

    public function testABanKeepsTheMessagesAndSignsTheAuditLog(): void
    {
        $this->server([new MockResponse('', ['http_code' => 204])])->ban('123456789', 'Triche répétée');

        self::assertSame('PUT', $this->requests[0]['method']);
        self::assertSame('https://discord.com/api/v10/guilds/'.self::GUILD.'/bans/123456789', $this->requests[0]['url']);
        self::assertSame(['delete_message_seconds' => 0], $this->requests[0]['body']);
        self::assertSame(DiscordServerSanctionsInterface::AUDIT_PREFIX.' Triche répétée', $this->requests[0]['auditReason'], 'recognised by story 39.7 as a ban of the site');
    }

    public function testAnUnbanOfSomeoneNotBannedIsNoError(): void
    {
        $server = $this->server([
            new MockResponse('', ['http_code' => 204]),
            new MockResponse('{"message":"Unknown Ban","code":10026}', ['http_code' => 404]),
        ]);

        $server->unban('123456789');
        $server->unban('123456789');

        self::assertSame(['DELETE', 'https://discord.com/api/v10/guilds/'.self::GUILD.'/bans/123456789'], [$this->requests[0]['method'], $this->requests[0]['url']]);
        self::assertSame(DiscordServerSanctionsInterface::AUDIT_PREFIX.' Levée de la sanction', $this->requests[0]['auditReason']);
    }

    public function testAMissingPermissionIsDefinitiveAndARateLimitPassing(): void
    {
        $server = $this->server([
            new MockResponse('{"message":"Missing Permissions","code":50013}', ['http_code' => 403]),
            new MockResponse('{}', ['http_code' => 429]),
        ]);

        foreach ([false, true] as $transient) {
            try {
                $server->ban('123456789', 'Triche');
                self::fail('must fail');
            } catch (DiscordServerSanctionException $e) {
                self::assertSame($transient, $e->transient);
            }
        }
    }

    public function testItNeedsTheBotAndTheServer(): void
    {
        self::assertTrue($this->server([])->isConfigured());
        self::assertFalse(new DiscordServerSanctions(new MockHttpClient([]), 'bot-token', '')->isConfigured());
        self::assertFalse(new DiscordServerSanctions(new MockHttpClient([]), '', self::GUILD)->isConfigured());
    }

    /**
     * @param list<MockResponse> $responses
     */
    private function server(array $responses): DiscordServerSanctions
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): MockResponse {
            $audit = null;
            $sent = $options['headers'] ?? [];
            foreach (is_array($sent) ? $sent : [] as $header) {
                if (is_string($header) && str_starts_with(strtolower($header), 'x-audit-log-reason:')) {
                    $audit = rawurldecode(trim(substr($header, strlen('x-audit-log-reason:'))));
                }
            }
            $body = is_string($options['body'] ?? null) && '' !== $options['body'] ? json_decode($options['body'], true) : null;
            $this->requests[] = ['method' => $method, 'url' => $url, 'body' => is_array($body) ? $body : null, 'auditReason' => $audit];

            return array_shift($responses) ?? new MockResponse('', ['http_code' => 204]);
        });

        return new DiscordServerSanctions($client, 'bot-token', self::GUILD);
    }
}
