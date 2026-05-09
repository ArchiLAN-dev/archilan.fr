<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Events\Domain\Event;
use App\Identity\Application\AuthSessionSigner;
use App\Identity\Domain\User;
use App\Registrations\Domain\Registration;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;

final class RegistrationEligibilityTest extends WebTestCase
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

    public function testAnonymousGets401(): void
    {
        $this->client->jsonRequest('GET', '/api/v1/events/nonexistent/registration-eligibility');
        self::assertResponseStatusCodeSame(401);
    }

    public function testAuthenticatedUserGets404ForUnknownEvent(): void
    {
        $user = $this->createUser('user@example.org', ['ROLE_USER']);
        $this->loginAs($user);

        $this->client->jsonRequest('GET', '/api/v1/events/nonexistent/registration-eligibility');
        self::assertResponseStatusCodeSame(404);
    }

    public function testPrivateEventIsNotEligible(): void
    {
        $user = $this->createUser('user@example.org', ['ROLE_USER']);
        $this->loginAs($user);

        $event = $this->createEvent(
            Event::STATUS_PUBLISHED,
            isPublic: false,
            registrationOpensAt: new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            registrationClosesAt: new \DateTimeImmutable('2027-01-01T00:00:00+00:00'),
        );

        $this->client->jsonRequest('GET', sprintf('/api/v1/events/%s/registration-eligibility', $event->getId()));

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertFalse($data['eligible']);
        self::assertSame('private_event', $data['reason']);
        self::assertNull($data['opensAt']);
    }

    public function testPrivateEventStillReportsRegistrationWindowBeforeAccessType(): void
    {
        $user = $this->createUser('user@example.org', ['ROLE_USER']);
        $this->loginAs($user);

        $event = $this->createEvent(
            Event::STATUS_PUBLISHED,
            isPublic: false,
            registrationOpensAt: new \DateTimeImmutable('2028-01-01T00:00:00+00:00'),
            registrationClosesAt: new \DateTimeImmutable('2028-06-01T00:00:00+00:00'),
        );

        $this->client->jsonRequest('GET', sprintf('/api/v1/events/%s/registration-eligibility', $event->getId()));

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertFalse($data['eligible']);
        self::assertSame('registration_not_open_yet', $data['reason']);
    }

    public function testCompletedEventIsNotEligible(): void
    {
        $user = $this->createUser('user@example.org', ['ROLE_USER']);
        $this->loginAs($user);

        $event = $this->createEvent(Event::STATUS_COMPLETED);

        $this->client->jsonRequest('GET', sprintf('/api/v1/events/%s/registration-eligibility', $event->getId()));

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertFalse($data['eligible']);
        self::assertSame('event_completed', $data['reason']);
    }

    public function testInProgressEventIsNotEligible(): void
    {
        $user = $this->createUser('user@example.org', ['ROLE_USER']);
        $this->loginAs($user);

        $event = $this->createEvent(
            Event::STATUS_IN_PROGRESS,
            registrationOpensAt: new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            registrationClosesAt: new \DateTimeImmutable('2027-01-01T00:00:00+00:00'),
        );

        $this->client->jsonRequest('GET', sprintf('/api/v1/events/%s/registration-eligibility', $event->getId()));

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertFalse($data['eligible']);
        self::assertSame('event_in_progress', $data['reason']);
    }

    public function testRegistrationNotOpenYet(): void
    {
        $user = $this->createUser('user@example.org', ['ROLE_USER']);
        $this->loginAs($user);

        $event = $this->createEvent(
            Event::STATUS_PUBLISHED,
            registrationOpensAt: new \DateTimeImmutable('2028-01-01T00:00:00+00:00'),
            registrationClosesAt: new \DateTimeImmutable('2028-06-01T00:00:00+00:00'),
        );

        $this->client->jsonRequest('GET', sprintf('/api/v1/events/%s/registration-eligibility', $event->getId()));

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertFalse($data['eligible']);
        self::assertSame('registration_not_open_yet', $data['reason']);
        self::assertIsString($data['opensAt']);
        self::assertStringContainsString('2028-01-01', $data['opensAt']);
    }

    public function testRegistrationClosed(): void
    {
        $user = $this->createUser('user@example.org', ['ROLE_USER']);
        $this->loginAs($user);

        $event = $this->createEvent(
            Event::STATUS_PUBLISHED,
            registrationOpensAt: new \DateTimeImmutable('2024-01-01T00:00:00+00:00'),
            registrationClosesAt: new \DateTimeImmutable('2024-06-01T00:00:00+00:00'),
        );

        $this->client->jsonRequest('GET', sprintf('/api/v1/events/%s/registration-eligibility', $event->getId()));

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertFalse($data['eligible']);
        self::assertSame('registration_closed', $data['reason']);
        self::assertNull($data['opensAt']);
    }

    public function testCapacityFull(): void
    {
        $user = $this->createUser('user@example.org', ['ROLE_USER']);
        $this->loginAs($user);

        $event = $this->createEvent(
            Event::STATUS_PUBLISHED,
            capacity: 1,
            registrationOpensAt: new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            registrationClosesAt: new \DateTimeImmutable('2027-01-01T00:00:00+00:00'),
        );
        $this->createRegistration($event->getId(), 'other-user-id');

        $this->client->jsonRequest('GET', sprintf('/api/v1/events/%s/registration-eligibility', $event->getId()));

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertFalse($data['eligible']);
        self::assertSame('capacity_full', $data['reason']);
    }

    public function testEligiblePublicOpenEvent(): void
    {
        $user = $this->createUser('user@example.org', ['ROLE_USER']);
        $this->loginAs($user);

        $event = $this->createEvent(
            Event::STATUS_PUBLISHED,
            registrationOpensAt: new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            registrationClosesAt: new \DateTimeImmutable('2027-01-01T00:00:00+00:00'),
        );

        $this->client->jsonRequest('GET', sprintf('/api/v1/events/%s/registration-eligibility', $event->getId()));

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertTrue($data['eligible']);
        self::assertNull($data['reason']);
        self::assertNull($data['opensAt']);
        $eventData = $data['event'];
        self::assertIsArray($eventData);
        self::assertSame($event->getId(), $eventData['id']);
        self::assertSame('Spring Sync 2027', $eventData['title']);
    }

    public function testDraftEventIsNotReachable(): void
    {
        $user = $this->createUser('user@example.org', ['ROLE_USER']);
        $this->loginAs($user);

        $event = $this->createEvent(Event::STATUS_DRAFT);

        $this->client->jsonRequest('GET', sprintf('/api/v1/events/%s/registration-eligibility', $event->getId()));

        self::assertResponseStatusCodeSame(404);
    }

    private function createEvent(
        string $status,
        bool $isPublic = true,
        int $capacity = 48,
        \DateTimeImmutable $registrationOpensAt = new \DateTimeImmutable('2027-05-01T00:00:00+00:00'),
        \DateTimeImmutable $registrationClosesAt = new \DateTimeImmutable('2027-05-30T18:00:00+00:00'),
    ): Event {
        $now = new \DateTimeImmutable('2026-04-30T10:00:00+00:00');
        $event = new Event(
            bin2hex(random_bytes(16)),
            'Spring Sync 2027',
            'Une session Archipelago de printemps.',
            $status,
            new \DateTimeImmutable('2027-05-31T10:00:00+00:00'),
            new \DateTimeImmutable('2027-05-31T22:00:00+00:00'),
            'Clermont-Ferrand',
            $capacity,
            $registrationOpensAt,
            $registrationClosesAt,
            $isPublic,
            null,
            false,
            [],
            null,
            null,
            $now,
            $now,
        );

        $this->entityManager->persist($event);
        $this->entityManager->flush();

        return $event;
    }

    private function createRegistration(string $eventId, string $userId): Registration
    {
        $now = new \DateTimeImmutable('2026-04-30T10:00:00+00:00');
        $registration = new Registration(bin2hex(random_bytes(16)), $eventId, $userId, Registration::STATUS_RESERVED, $now, $now);
        $this->entityManager->persist($registration);
        $this->entityManager->flush();

        return $registration;
    }

    /**
     * @param list<string> $roles
     */
    private function createUser(string $email, array $roles): User
    {
        $now = new \DateTimeImmutable('2026-04-30T10:00:00+00:00');
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
