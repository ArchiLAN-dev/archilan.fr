<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Events\Domain\Event;
use App\Payments\Domain\HelloAssoSyncLog;

final class AdminSyncStatusTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    public function testReturnsEmptyLogsWhenNoSyncAttemptted(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $event = $this->makeEvent('archilan-spring-2027');
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', sprintf('/api/v1/admin/events/%s/payments/sync/status', $event->getId()));

        self::assertResponseStatusCodeSame(200);
        $response = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($response);
        self::assertIsArray($response['data']);
        self::assertSame('archilan-spring-2027', $response['data']['formSlug']);
        self::assertSame([], $response['data']['recentSyncs']);
    }

    public function testReturnsNullFormSlugWhenEventHasNone(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $event = $this->makeEvent(null);
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', sprintf('/api/v1/admin/events/%s/payments/sync/status', $event->getId()));

        self::assertResponseStatusCodeSame(200);
        $response = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($response);
        self::assertIsArray($response['data']);
        self::assertNull($response['data']['formSlug']);
        self::assertSame([], $response['data']['recentSyncs']);
    }

    public function testReturnsSyncLogsOrderedByMostRecentFirst(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $event = $this->makeEvent('archilan-spring-2027');
        $this->createSyncLog('archilan-spring-2027', true, null, new \DateTimeImmutable('2026-04-25T09:00:00+00:00'));
        $this->createSyncLog('archilan-spring-2027', false, 'HTTP 503', new \DateTimeImmutable('2026-04-25T10:00:00+00:00'));
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', sprintf('/api/v1/admin/events/%s/payments/sync/status', $event->getId()));

        self::assertResponseStatusCodeSame(200);
        $response = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($response);
        self::assertIsArray($response['data']);
        $recentSyncs = $response['data']['recentSyncs'];
        self::assertIsArray($recentSyncs);
        self::assertCount(2, $recentSyncs);
        self::assertIsArray($recentSyncs[0]);
        self::assertFalse($recentSyncs[0]['success']);
        self::assertSame('HTTP 503', $recentSyncs[0]['errorMessage']);
        self::assertIsArray($recentSyncs[1]);
        self::assertTrue($recentSyncs[1]['success']);
    }

    public function testReturns404ForUnknownEvent(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', '/api/v1/admin/events/nonexistent/payments/sync/status');

        self::assertResponseStatusCodeSame(404);
    }

    public function testStandardCannotReadSyncStatus(): void
    {
        $user = $this->createUser('lambda@example.org', ['ROLE_USER']);
        $event = $this->makeEvent('archilan-spring-2027');
        $this->loginAs($user);

        $this->client->jsonRequest('GET', sprintf('/api/v1/admin/events/%s/payments/sync/status', $event->getId()));

        self::assertResponseStatusCodeSame(403);
    }

    public function testAnonymousCannotReadSyncStatus(): void
    {
        $this->client->jsonRequest('GET', '/api/v1/admin/events/any/payments/sync/status');

        self::assertResponseStatusCodeSame(401);
    }

    private function makeEvent(?string $helloassoFormSlug): Event
    {
        $now = new \DateTimeImmutable('2026-04-25T10:00:00+00:00');
        $event = $this->createEvent(
            'Spring Sync 2027',
            new \DateTimeImmutable('2027-05-31T10:00:00+00:00'),
            new \DateTimeImmutable('2027-05-31T22:00:00+00:00'),
            capacity: 48,
            published: true,
        );
        if (null !== $helloassoFormSlug) {
            $event->setHelloassoFormSlug($helloassoFormSlug, $now);
            $this->entityManager->flush();
        }

        return $event;
    }

    private function createSyncLog(string $formSlug, bool $success, ?string $errorMessage, \DateTimeImmutable $attemptAt): void
    {
        $log = $success
            ? HelloAssoSyncLog::fromSuccess($formSlug, $attemptAt)
            : HelloAssoSyncLog::fromFailure($formSlug, $errorMessage ?? 'Unknown error', $attemptAt);

        $this->entityManager->persist($log);
        $this->entityManager->flush();
    }
}
