<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Events\Domain\Event;
use App\Identity\Domain\User;
use Doctrine\ORM\Tools\SchemaTool;

final class HelloAssoSyncTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $metadata = [
            $this->entityManager->getClassMetadata(User::class),
            $this->entityManager->getClassMetadata(Event::class),
        ];
        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    public function testAdminGetsOperationalErrorWhenHelloAssoApiConfigIsMissing(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $event = $this->makeEvent(helloassoFormSlug: 'archilan-spring-2027');
        $this->loginAs($admin);

        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/events/%s/payments/sync', $event->getId()));

        self::assertResponseStatusCodeSame(503);
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['error']);
        self::assertSame('helloasso_not_configured', $response['error']['code']);
        self::assertIsString($response['error']['message']);
        self::assertStringContainsString('HELLOASSO_', $response['error']['message']);
    }

    public function testAdminGets422WhenEventHasNoFormSlug(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $event = $this->makeEvent(helloassoFormSlug: null);
        $this->loginAs($admin);

        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/events/%s/payments/sync', $event->getId()));

        self::assertResponseStatusCodeSame(422);
    }

    public function testAdminGets404ForUnknownEvent(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);

        $this->client->jsonRequest('POST', '/api/v1/admin/events/nonexistent/payments/sync');

        self::assertResponseStatusCodeSame(404);
    }

    public function testStandardCannotTriggerSync(): void
    {
        $user = $this->createUser('lambda@example.org', ['ROLE_USER']);
        $event = $this->makeEvent(helloassoFormSlug: 'archilan-spring-2027');
        $this->loginAs($user);

        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/events/%s/payments/sync', $event->getId()));

        self::assertResponseStatusCodeSame(403);
    }

    public function testAnonymousCannotTriggerSync(): void
    {
        $this->client->jsonRequest('POST', '/api/v1/admin/events/any/payments/sync');

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
}
