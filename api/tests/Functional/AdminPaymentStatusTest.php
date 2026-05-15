<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Events\Domain\Event;
use App\Events\Domain\EventPrivateAccessLog;
use App\Identity\Domain\User;
use App\Payments\Domain\HelloAssoOrder;
use App\Registrations\Domain\Registration;
use Doctrine\ORM\Tools\SchemaTool;

final class AdminPaymentStatusTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $metadata = [
            $this->entityManager->getClassMetadata(User::class),
            $this->entityManager->getClassMetadata(Event::class),
            $this->entityManager->getClassMetadata(Registration::class),
            $this->entityManager->getClassMetadata(HelloAssoOrder::class),
            $this->entityManager->getClassMetadata(EventPrivateAccessLog::class),
        ];
        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    public function testPaymentFoundWhenOrderMatchesFormSlugAndEmail(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $participant = $this->createUser('player@example.org', ['ROLE_USER']);
        $event = $this->makeEvent('archilan-spring-2027');
        $registration = $this->makeRegistration($event, $participant);
        $this->createOrder('archilan-spring-2027', 'player@example.org', 'processed', 2000, false);
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', sprintf('/api/v1/admin/events/%s/registrations/%s', $event->getId(), $registration->getId()));

        self::assertResponseStatusCodeSame(200);
        $response = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($response);
        self::assertIsArray($response['data']);
        self::assertArrayHasKey('payment', $response['data']);
        self::assertIsArray($response['data']['payment']);
        self::assertSame('processed', $response['data']['payment']['status']);
        self::assertSame(2000, $response['data']['payment']['amountCents']);
        self::assertFalse($response['data']['payment']['isStale']);
    }

    public function testPaymentNullWhenNoOrderForEmail(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $participant = $this->createUser('nopayment@example.org', ['ROLE_USER']);
        $event = $this->makeEvent('archilan-spring-2027');
        $registration = $this->makeRegistration($event, $participant);
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', sprintf('/api/v1/admin/events/%s/registrations/%s', $event->getId(), $registration->getId()));

        self::assertResponseStatusCodeSame(200);
        $response = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($response);
        self::assertIsArray($response['data']);
        self::assertArrayHasKey('payment', $response['data']);
        self::assertNull($response['data']['payment']);
    }

    public function testPaymentNullWhenEventHasNoFormSlug(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $participant = $this->createUser('player@example.org', ['ROLE_USER']);
        $event = $this->makeEvent(null);
        $registration = $this->makeRegistration($event, $participant);
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', sprintf('/api/v1/admin/events/%s/registrations/%s', $event->getId(), $registration->getId()));

        self::assertResponseStatusCodeSame(200);
        $response = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($response);
        self::assertIsArray($response['data']);
        self::assertNull($response['data']['payment']);
    }

    public function testPaymentIsStaleWhenSyncedMoreThan24HoursAgo(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $participant = $this->createUser('stale@example.org', ['ROLE_USER']);
        $event = $this->makeEvent('archilan-spring-2027');
        $registration = $this->makeRegistration($event, $participant);
        $this->createOrder('archilan-spring-2027', 'stale@example.org', 'processed', 1500, true);
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', sprintf('/api/v1/admin/events/%s/registrations/%s', $event->getId(), $registration->getId()));

        self::assertResponseStatusCodeSame(200);
        $response = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($response);
        self::assertIsArray($response['data']);
        self::assertIsArray($response['data']['payment']);
        self::assertTrue($response['data']['payment']['isStale']);
    }

    private function makeEvent(?string $helloassoFormSlug): Event
    {
        $now = new \DateTimeImmutable('2026-04-25T10:00:00+00:00');
        $event = $this->createEvent(
            'Spring 2027',
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

    private function makeRegistration(Event $event, User $participant): Registration
    {
        return $this->createRegistration($event->getId(), $participant->getId());
    }

    private function createOrder(string $formSlug, string $payerEmail, string $status, int $amountCents, bool $stale): HelloAssoOrder
    {
        $now = $stale
            ? new \DateTimeImmutable('-48 hours')
            : new \DateTimeImmutable();

        $order = HelloAssoOrder::fromHelloAsso(
            random_int(100000, 999999),
            'evenements',
            $formSlug,
            $status,
            $amountCents,
            $payerEmail,
            'Jean',
            'Dupont',
            new \DateTimeImmutable('2026-04-20T14:00:00+00:00'),
            $now,
        );

        $this->entityManager->persist($order);
        $this->entityManager->flush();

        return $order;
    }
}
