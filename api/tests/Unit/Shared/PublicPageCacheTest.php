<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use App\Shared\Application\Exception\PublicPageCacheUnavailableException;
use App\Shared\Application\Handler\RevalidatePublicPagesJobHandler;
use App\Shared\Application\Message\RevalidatePublicPagesJob;
use App\Shared\Application\Port\PublicPageCacheInterface;
use App\Shared\Application\Port\PublicPageCacheOutcome;
use App\Shared\Infrastructure\Http\HttpPublicPageCache;
use App\Shared\Presentation\Command\RevalidateFrontendCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Story 34.10 AC 5 to 7: the call to the frontend's `POST /api/revalidate`, the job that makes it, and the
 * manual command.
 */
final class PublicPageCacheTest extends TestCase
{
    public function testTheFrontendIsCalledOnTheDockerNetworkWithTheSecret(): void
    {
        $sent = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$sent): MockResponse {
            $sent = ['method' => $method, 'url' => $url, 'headers' => $options['headers'] ?? [], 'body' => $options['body'] ?? null];

            return new MockResponse('{"revalidated":["events"]}', ['http_code' => 200]);
        });
        $cache = new HttpPublicPageCache($client, new NullLogger(), 'http://archilan-frontend:3000/', 's3cret');

        self::assertSame(PublicPageCacheOutcome::Revalidated, $cache->invalidate(['events', 'event:abc']));
        self::assertSame('POST', $sent['method']);
        self::assertSame('http://archilan-frontend:3000/api/revalidate', $sent['url']);
        self::assertIsArray($sent['headers']);
        self::assertContains('Authorization: Bearer s3cret', $sent['headers']);
        self::assertSame('{"tags":["events","event:abc"]}', $sent['body']);
    }

    /**
     * @return iterable<string, array{int, PublicPageCacheOutcome}>
     */
    public static function answers(): iterable
    {
        yield 'wrong secret' => [401, PublicPageCacheOutcome::Rejected];
        yield 'invalid tags' => [400, PublicPageCacheOutcome::Rejected];
        yield 'secret not set on the frontend' => [503, PublicPageCacheOutcome::Unavailable];
        yield 'frontend error' => [500, PublicPageCacheOutcome::Unavailable];
    }

    #[DataProvider('answers')]
    public function testTheFrontendAnswerTellsWhetherToRetry(int $status, PublicPageCacheOutcome $expected): void
    {
        $cache = new HttpPublicPageCache(new MockHttpClient(new MockResponse('{}', ['http_code' => $status])), new NullLogger(), 'http://frontend:3000', 's3cret');

        self::assertSame($expected, $cache->invalidate(['events']));
    }

    public function testAnUnreachableFrontendIsWorthARetry(): void
    {
        $cache = new HttpPublicPageCache(new MockHttpClient(new MockResponse('', ['error' => 'Connection refused'])), new NullLogger(), 'http://frontend:3000', 's3cret');

        self::assertSame(PublicPageCacheOutcome::Unavailable, $cache->invalidate(['events']));
    }

    public function testWithoutUrlOrSecretNothingIsCalled(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => throw new \LogicException('no call expected'));

        self::assertSame(PublicPageCacheOutcome::Disabled, new HttpPublicPageCache($client, new NullLogger(), '', 's3cret')->invalidate(['events']));
        self::assertSame(PublicPageCacheOutcome::Disabled, new HttpPublicPageCache($client, new NullLogger(), 'http://frontend:3000', '')->invalidate(['events']));
    }

    public function testTheJobIsRetriedOnlyWhenTheFrontendDidNotAnswer(): void
    {
        $job = new RevalidatePublicPagesJob(['events']);

        new RevalidatePublicPagesJobHandler($this->cacheAnswering(PublicPageCacheOutcome::Revalidated), new NullLogger())($job);
        new RevalidatePublicPagesJobHandler($this->cacheAnswering(PublicPageCacheOutcome::Disabled), new NullLogger())($job);
        new RevalidatePublicPagesJobHandler($this->cacheAnswering(PublicPageCacheOutcome::Rejected), new NullLogger())($job);

        $this->expectException(PublicPageCacheUnavailableException::class);
        new RevalidatePublicPagesJobHandler($this->cacheAnswering(PublicPageCacheOutcome::Unavailable), new NullLogger())($job);
    }

    public function testTheCommandRevalidatesTheGivenTags(): void
    {
        $cache = $this->cacheAnswering(PublicPageCacheOutcome::Revalidated);
        $tester = new CommandTester(new RevalidateFrontendCommand($cache));

        self::assertSame(Command::SUCCESS, $tester->execute(['tags' => ['events', 'event:fef2ae3e']]));
        self::assertStringContainsString('Revalidated: events, event:fef2ae3e', $tester->getDisplay());
    }

    public function testTheCommandRefusesAnInvalidTagAndReportsAFailure(): void
    {
        $tester = new CommandTester(new RevalidateFrontendCommand($this->cacheAnswering(PublicPageCacheOutcome::Revalidated)));
        self::assertSame(Command::INVALID, $tester->execute(['tags' => ['Event: X']]));

        $tester = new CommandTester(new RevalidateFrontendCommand($this->cacheAnswering(PublicPageCacheOutcome::Rejected)));
        self::assertSame(Command::FAILURE, $tester->execute(['tags' => ['events']]));
        self::assertStringContainsString('check REVALIDATE_SECRET', $tester->getDisplay());
    }

    private function cacheAnswering(PublicPageCacheOutcome $outcome): PublicPageCacheInterface
    {
        return new readonly class($outcome) implements PublicPageCacheInterface {
            public function __construct(private PublicPageCacheOutcome $outcome)
            {
            }

            public function invalidate(array $tags): PublicPageCacheOutcome
            {
                return $this->outcome;
            }
        };
    }
}
