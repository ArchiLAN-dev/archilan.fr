<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Events\Domain\Event;
use App\Identity\Application\AuthSessionSigner;
use App\Identity\Domain\User;
use App\Payments\Application\HelloAssoConfig;
use App\Registrations\Domain\Registration;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;

final class HelloAssoCheckoutTest extends WebTestCase
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
        ];
        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    public function testAdminCanSetHelloassoFormSlug(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $event = $this->createPublishedEvent();
        $this->loginAs($admin);

        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/events/%s', $event->getId()), [
            ...$this->validPayload(),
            'helloassoFormSlug' => 'archilan-spring-2027',
        ]);

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['data']);
        self::assertSame('archilan-spring-2027', $response['data']['helloassoFormSlug']);

        $this->entityManager->clear();
        $stored = $this->entityManager->find(Event::class, $event->getId());
        self::assertInstanceOf(Event::class, $stored);
        self::assertSame('archilan-spring-2027', $stored->getHelloassoFormSlug());
    }

    public function testAdminCanClearHelloassoFormSlug(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $event = $this->createPublishedEvent(helloassoFormSlug: 'archilan-spring-2027');
        $this->loginAs($admin);

        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/events/%s', $event->getId()), [
            ...$this->validPayload(),
            'helloassoFormSlug' => '',
        ]);

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['data']);
        self::assertNull($response['data']['helloassoFormSlug']);

        $this->entityManager->clear();
        $stored = $this->entityManager->find(Event::class, $event->getId());
        self::assertInstanceOf(Event::class, $stored);
        self::assertNull($stored->getHelloassoFormSlug());
    }

    public function testPublicEventExposesCheckoutEmbedUrlAsNullWhenNoFormSlug(): void
    {
        $event = $this->createPublishedEvent();

        $this->client->jsonRequest('GET', sprintf('/api/v1/events/%s', $event->getId()));

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['data']);
        self::assertNull($response['data']['checkoutEmbedUrl']);
        self::assertFalse($response['data']['checkoutUnavailable']);
    }

    public function testPublicEventExposesCheckoutEmbedUrlAsNullWhenHelloassoUnconfigured(): void
    {
        // HELLOASSO_ORGANIZATION_SLUG is empty in the test environment - buildCheckoutEmbedUrl() returns null gracefully.
        $event = $this->createPublishedEvent(helloassoFormSlug: 'archilan-spring-2027');

        $this->client->jsonRequest('GET', sprintf('/api/v1/events/%s', $event->getId()));

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['data']);
        self::assertNull($response['data']['checkoutEmbedUrl']);
        self::assertTrue($response['data']['checkoutUnavailable']);
    }

    public function testPublicEventListIncludesCheckoutEmbedUrlField(): void
    {
        $this->createPublishedEvent();

        $this->client->jsonRequest('GET', '/api/v1/events');

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['data']);
        self::assertCount(1, $response['data']);
        self::assertIsArray($response['data'][0]);
        self::assertArrayHasKey('checkoutEmbedUrl', $response['data'][0]);
    }

    public function testPublicOpenEventExposesCheckoutEmbedUrlWhenHelloassoIsConfigured(): void
    {
        $this->configureHelloAsso();
        $event = $this->createPublishedEvent(helloassoFormSlug: 'archilan-spring-2027');

        $this->client->jsonRequest('GET', sprintf('/api/v1/events/%s', $event->getId()));

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['data']);
        self::assertSame(
            'https://www.helloasso-sandbox.com/associations/archilan/evenements/archilan-spring-2027/widget',
            $response['data']['checkoutEmbedUrl'],
        );
        self::assertFalse($response['data']['checkoutUnavailable']);
    }

    public function testPrivateEventDoesNotExposeCheckoutEmbedUrl(): void
    {
        $this->configureHelloAsso();
        $event = $this->createPublishedEvent(helloassoFormSlug: 'archilan-spring-2027', isPublic: false);

        $this->client->jsonRequest('GET', sprintf('/api/v1/events/%s', $event->getId()));

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['data']);
        self::assertNull($response['data']['checkoutEmbedUrl']);
        self::assertFalse($response['data']['checkoutUnavailable']);
    }

    public function testCompletedEventDoesNotExposeCheckoutEmbedUrl(): void
    {
        $this->configureHelloAsso();
        $event = $this->createPublishedEvent(helloassoFormSlug: 'archilan-spring-2027', status: Event::STATUS_COMPLETED);

        $this->client->jsonRequest('GET', sprintf('/api/v1/events/%s', $event->getId()));

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['data']);
        self::assertNull($response['data']['checkoutEmbedUrl']);
        self::assertFalse($response['data']['checkoutUnavailable']);
    }

    public function testFullEventDoesNotExposeCheckoutEmbedUrl(): void
    {
        $this->configureHelloAsso();
        $event = $this->createPublishedEvent(helloassoFormSlug: 'archilan-spring-2027', capacity: 1);
        $this->createRegistration($event->getId(), 'some-user-id');

        $this->client->jsonRequest('GET', sprintf('/api/v1/events/%s', $event->getId()));

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['data']);
        self::assertNull($response['data']['checkoutEmbedUrl']);
        self::assertFalse($response['data']['checkoutUnavailable']);
    }

    public function testFutureRegistrationWindowDoesNotExposeCheckoutEmbedUrl(): void
    {
        $this->configureHelloAsso();
        $event = $this->createPublishedEvent(
            helloassoFormSlug: 'archilan-spring-2027',
            registrationOpensAt: new \DateTimeImmutable('+2 days'),
            registrationClosesAt: new \DateTimeImmutable('+3 days'),
        );

        $this->client->jsonRequest('GET', sprintf('/api/v1/events/%s', $event->getId()));

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['data']);
        self::assertNull($response['data']['checkoutEmbedUrl']);
        self::assertFalse($response['data']['checkoutUnavailable']);
    }

    private function createPublishedEvent(
        ?string $helloassoFormSlug = null,
        string $status = Event::STATUS_PUBLISHED,
        bool $isPublic = true,
        int $capacity = 48,
        ?\DateTimeImmutable $registrationOpensAt = null,
        ?\DateTimeImmutable $registrationClosesAt = null,
    ): Event {
        $now = new \DateTimeImmutable('2026-04-25T10:00:00+00:00');
        $event = new Event(
            bin2hex(random_bytes(16)),
            'Spring Sync 2027',
            'Une session Archipelago.',
            $status,
            new \DateTimeImmutable('+30 days'),
            new \DateTimeImmutable('+31 days'),
            'Clermont-Ferrand',
            $capacity,
            $registrationOpensAt ?? new \DateTimeImmutable('-1 day'),
            $registrationClosesAt ?? new \DateTimeImmutable('+20 days'),
            $isPublic,
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
     * @return array<string, mixed>
     */
    private function validPayload(): array
    {
        return [
            'title' => 'Spring Sync 2027',
            'description' => 'Une session Archipelago.',
            'startsAt' => '2027-05-31T10:00:00+00:00',
            'endsAt' => '2027-05-31T22:00:00+00:00',
            'venue' => 'Clermont-Ferrand',
            'capacity' => 48,
            'registrationOpensAt' => '2027-05-01T10:00:00+00:00',
            'registrationClosesAt' => '2027-05-30T18:00:00+00:00',
            'isPublic' => true,
        ];
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

    private function loginAs(User $user): void
    {
        $this->client->getCookieJar()->set(
            new Cookie(AuthSessionSigner::COOKIE_NAME, $this->authSessionSigner->sign($user->getId())),
        );
    }

    private function createRegistration(string $eventId, string $userId): Registration
    {
        $now = new \DateTimeImmutable('2026-04-25T10:00:00+00:00');
        $registration = new Registration(bin2hex(random_bytes(16)), $eventId, $userId, Registration::STATUS_RESERVED, $now, $now);
        $this->entityManager->persist($registration);
        $this->entityManager->flush();

        return $registration;
    }

    private function configureHelloAsso(): void
    {
        self::getContainer()->set(HelloAssoConfig::class, new HelloAssoConfig('client-id', 'secret', 'archilan', true));
    }

    /**
     * @return array<mixed>
     */
    private function decodedJsonResponse(): array
    {
        $decoded = json_decode($this->client->getResponse()->getContent() ?: '', true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
