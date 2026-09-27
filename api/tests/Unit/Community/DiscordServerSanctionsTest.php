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

    public function testATimeoutRunsUntilTheGivenMomentAndSignsTheAuditLog(): void
    {
        $server = $this->server([new MockResponse('{"user":{"id":"123456789"}}')]);

        self::assertTrue($server->timeout('123456789', new \DateTimeImmutable('2026-10-10T08:00:00+00:00'), 'Comportement'));

        self::assertSame(['PATCH', 'https://discord.com/api/v10/guilds/'.self::GUILD.'/members/123456789'], [$this->requests[0]['method'], $this->requests[0]['url']]);
        self::assertSame(['communication_disabled_until' => '2026-10-10T08:00:00+00:00'], $this->requests[0]['body']);
        self::assertSame(DiscordServerSanctionsInterface::AUDIT_PREFIX.' Comportement', $this->requests[0]['auditReason']);
    }

    public function testSomeoneOffTheServerCannotBeTimedOutAndThatIsNoError(): void
    {
        $server = $this->server([
            new MockResponse('{"message":"Unknown Member","code":10007}', ['http_code' => 404]),
            new MockResponse('{"message":"Unknown Member","code":10007}', ['http_code' => 404]),
        ]);

        self::assertFalse($server->timeout('123456789', new \DateTimeImmutable('2026-10-10T08:00:00+00:00'), 'Comportement'));
        $server->clearTimeout('123456789');

        self::assertSame(['communication_disabled_until' => null], $this->requests[1]['body']);
    }

    public function testTheWholeBanListIsReadPageByPage(): void
    {
        $server = $this->server([
            new MockResponse((string) json_encode([
                ['user' => ['id' => '11', 'username' => 'lone'], 'reason' => 'Raid'],
                ['user' => ['id' => '12', 'username' => 'spam'], 'reason' => null],
            ])),
            new MockResponse((string) json_encode([
                ['user' => ['id' => '13', 'username' => 'bot'], 'reason' => '[archilan.fr] Triche'],
            ])),
        ], banPageSize: 2);

        $bans = $server->bans();

        self::assertSame('https://discord.com/api/v10/guilds/'.self::GUILD.'/bans?limit=2', $this->requests[0]['url']);
        self::assertSame('https://discord.com/api/v10/guilds/'.self::GUILD.'/bans?limit=2&after=12', $this->requests[1]['url'], 'the next page starts after the last user');
        self::assertCount(3, $bans);
        self::assertSame(['11', 'lone', 'Raid'], [$bans[0]->discordUserId, $bans[0]->username, $bans[0]->reason]);
        self::assertNull($bans[1]->reason);
        self::assertTrue($bans[2]->postedBySite());
        self::assertFalse($bans[0]->postedBySite());
    }

    public function testAFailedPageFailsTheWholeList(): void
    {
        $server = $this->server([
            new MockResponse((string) json_encode([['user' => ['id' => '11', 'username' => 'a'], 'reason' => null], ['user' => ['id' => '12', 'username' => 'b'], 'reason' => null]])),
            new MockResponse('{}', ['http_code' => 503]),
        ], banPageSize: 2);

        $this->expectException(DiscordServerSanctionException::class);
        $server->bans();
    }

    public function testBanAuthorsComeFromTheAuditLog(): void
    {
        $server = $this->server([new MockResponse((string) json_encode([
            'audit_log_entries' => [
                ['target_id' => '11', 'user_id' => '900', 'action_type' => 22, 'reason' => 'Raid'],
                ['target_id' => '12', 'user_id' => '901', 'action_type' => 22],
                ['target_id' => '11', 'user_id' => '901', 'action_type' => 22],
            ],
            'users' => [['id' => '900', 'username' => 'modo'], ['id' => '901', 'username' => 'autre']],
        ]))]);

        self::assertSame(['11' => 'modo', '12' => 'autre'], $server->banAuthors(), 'the most recent ban of a member wins');
        self::assertSame('https://discord.com/api/v10/guilds/'.self::GUILD.'/audit-logs?action_type=22&limit=100', $this->requests[0]['url']);
    }

    public function testWithoutTheAuditLogPermissionTheAuthorsAreUnknown(): void
    {
        $server = $this->server([new MockResponse('{"message":"Missing Permissions","code":50013}', ['http_code' => 403])]);

        self::assertSame([], $server->banAuthors());
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
    private function server(array $responses, int $banPageSize = 1000): DiscordServerSanctions
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

        return new DiscordServerSanctions($client, 'bot-token', self::GUILD, $banPageSize);
    }
}
