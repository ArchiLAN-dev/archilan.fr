<?php

declare(strict_types=1);

namespace App\Tests\Unit\Membership;

use App\Membership\Infrastructure\DolibarrClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class DolibarrClientTest extends TestCase
{
    public function testUpsertMemberThrowsWhenSearchFails(): void
    {
        $client = new DolibarrClient(
            new MockHttpClient(new MockResponse('Service Unavailable', ['http_code' => 503])),
            $this->createStub(LoggerInterface::class),
            'https://dolibarr.example.test',
            'secret-key',
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Dolibarr search member failed');

        $client->upsertMember('jean@example.org', 'Jean', 'active', new \DateTimeImmutable('2027-05-16'));
    }

    public function testUpsertMemberThrowsWhenCreateFails(): void
    {
        $client = new DolibarrClient(
            new MockHttpClient([
                new MockResponse('[]', ['http_code' => 200]),
                new MockResponse('Bad Request', ['http_code' => 400]),
            ]),
            $this->createStub(LoggerInterface::class),
            'https://dolibarr.example.test',
            'secret-key',
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Dolibarr create member failed');

        $client->upsertMember('jean@example.org', 'Jean', 'active', new \DateTimeImmutable('2027-05-16'));
    }
}
