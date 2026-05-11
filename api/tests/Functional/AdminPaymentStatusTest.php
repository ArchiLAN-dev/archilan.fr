<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Events\Domain\Event;
use App\Events\Domain\EventPrivateAccessLog;
use App\Identity\Application\AuthSessionSigner;
use App\Identity\Domain\User;
use App\Payments\Domain\HelloAssoOrder;
use App\Registrations\Domain\Registration;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;

final class AdminPaymentStatusTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private AuthSessionSigner $authSessionSigner;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;

        $authSessionSigner = self::getContainer()->get(AuthSessionSigner::class);
        self::assertInstanceOf(AuthSessionSigner::class, $authSessionSigner);
        $this->authSessionSigner = $authSessionSigner;

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
        $event = $this->createEvent('archilan-spring-2027');
        $registration = $this->createRegistration($event, $participant);
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
        $event = $this->createEvent('archilan-spring-2027');
        $registration = $this->createRegistration($event, $participant);
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
        $event = $this->createEvent(null);
        $registration = $this->createRegistration($event, $participant);
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
        $event = $this->createEvent('archilan-spring-2027');
        $registration = $this->createRegistration($event, $participant);
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

    private function createEvent(?string $helloassoFormSlug): Event
    {
        $now = new \DateTimeImmutable('2026-04-25T10:00:00+00:00');
        $event = new Event(
            bin2hex(random_bytes(16)),
            'Spring 2027',
            'Une session Archipelago.',
            Event::STATUS_PUBLISHED,
            new \DateTimeImmutable('2027-05-31T10:00:00+00:00'),
            new \DateTimeImmutable('2027-05-31T22:00:00+00:00'),
            'Clermont-Ferrand',
            48,
            new \DateTimeImmutable('2027-05-01T10:00:00+00:00'),
            new \DateTimeImmutable('2027-05-30T18:00:00+00:00'),
            true,
            null,
            false,
            [],
            null,
            null,
            $now,
            $now,
            null,
            null,
            $helloassoFormSlug,
        );

        $this->entityManager->persist($event);
        $this->entityManager->flush();

        return $event;
    }

    /**
     * @param list<string> $roles
     */
    private function createUser(string $email, array $roles): User
    {
        $now = new \DateTimeImmutable('2026-04-25T10:00:00+00:00');
        $user = new User(
            bin2hex(random_bytes(16)),
            $email,
            mb_strtolower($email),
            null,
            'test-password-hash',
            $roles,
            $now,
            $now,
            $now,
        );

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function createRegistration(Event $event, User $participant): Registration
    {
        $now = new \DateTimeImmutable('2026-04-25T10:00:00+00:00');
        $registration = new Registration(
            bin2hex(random_bytes(16)),
            $event->getId(),
            $participant->getId(),
            Registration::STATUS_RESERVED,
            $now,
            $now,
        );

        $this->entityManager->persist($registration);
        $this->entityManager->flush();

        return $registration;
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

    private function loginAs(User $user): void
    {
        $this->client->getCookieJar()->set(
            new Cookie(AuthSessionSigner::COOKIE_NAME, $this->authSessionSigner->sign($user->getId())),
        );
    }
}
