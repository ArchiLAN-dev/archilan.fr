<?php

declare(strict_types=1);

namespace App\Tests\Unit\Wallet;

use App\Wallet\Application\Exception\MinimapperzUnauthorizedException;
use App\Wallet\Application\Exception\MinimapperzUnavailableException;
use App\Wallet\Infrastructure\Http\MinimapperzHttpClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class MinimapperzHttpClientTest extends TestCase
{
    private const string KEY = 'mmz_ev_secret-key';

    public function testTheEventIsReadWithTheKeyAsBearer(): void
    {
        $seen = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = [$url, self::authorization($options)];

            return new MockResponse((string) json_encode([
                'slug' => 'archilan-test',
                'name' => 'ArchiLAN test',
                'opens_at' => '2026-11-02T20:25:00Z',
                'closes_at' => '2026-11-07T20:25:00Z',
                'is_open' => false,
                'ranking_mode' => 'best_game',
            ]));
        });

        $event = new MinimapperzHttpClient($http, 'https://mmz.test/')->event('archilan-test', self::KEY);

        self::assertSame(['https://mmz.test/api/v1/organiser/events/archilan-test', 'Authorization: Bearer '.self::KEY], $seen);
        self::assertSame('ArchiLAN test', $event->name);
        self::assertFalse($event->isOpen);
        self::assertSame('2026-11-07T20:25:00+00:00', $event->closesAt->format(\DateTimeInterface::ATOM));
    }

    public function testARefusedKeyIsUnauthorized(): void
    {
        $client = new MinimapperzHttpClient(new MockHttpClient(new MockResponse('{"detail":"Invalid API key for this event"}', ['http_code' => 401])), 'https://mmz.test');

        $this->expectException(MinimapperzUnauthorizedException::class);
        $client->event('archilan-test', self::KEY);
    }

    public function testAnUnexpectedBodyIsUnavailable(): void
    {
        $client = new MinimapperzHttpClient(new MockHttpClient(new MockResponse('{"slug":"archilan-test"}')), 'https://mmz.test');

        $this->expectException(MinimapperzUnavailableException::class);
        $client->event('archilan-test', self::KEY);
    }

    public function testANetworkFailureIsUnavailableAndNeverTellsTheKey(): void
    {
        $client = new MinimapperzHttpClient(new MockHttpClient(static function (): never {
            throw new TransportException('Connection refused for '.self::KEY);
        }), 'https://mmz.test');

        try {
            $client->event('archilan-test', self::KEY);
            self::fail('Expected MinimapperzUnavailableException');
        } catch (MinimapperzUnavailableException $e) {
            self::assertStringNotContainsString(self::KEY, $e->getMessage());
        }
    }

    /** @param array<array-key, mixed> $options */
    private static function authorization(array $options): mixed
    {
        $headers = $options['normalized_headers'] ?? null;
        $values = is_array($headers) ? ($headers['authorization'] ?? null) : null;

        return is_array($values) ? ($values[0] ?? null) : null;
    }
}
