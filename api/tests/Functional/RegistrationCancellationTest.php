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

final class RegistrationCancellationTest extends WebTestCase
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

    public function testAnonymousGets401OnDelete(): void
    {
        $this->client->jsonRequest('DELETE', '/api/v1/registrations/nonexistent');
        self::assertResponseStatusCodeSame(401);
    }

    public function testUnknownRegistrationReturns404(): void
    {
        $user = $this->createUser('user@example.org');
        $this->loginAs($user);

        $this->client->jsonRequest('DELETE', '/api/v1/registrations/nonexistent');
        self::assertResponseStatusCodeSame(404);
    }

    public function testRegistrationOwnedByOtherUserReturns404(): void
    {
        $owner = $this->createUser('owner@example.org');
        $other = $this->createUser('other@example.org');
        $event = $this->createEvent(Event::STATUS_PUBLISHED);
        $registration = $this->createRegistration($event->getId(), $owner->getId());

        $this->loginAs($other);
        $this->client->jsonRequest('DELETE', sprintf('/api/v1/registrations/%s', $registration->getId()));
        self::assertResponseStatusCodeSame(404);
    }

    public function testAlreadyCancelledRegistrationReturns404(): void
    {
        $user = $this->createUser('user@example.org');
        $event = $this->createEvent(Event::STATUS_PUBLISHED);
        $registration = $this->createRegistration($event->getId(), $user->getId(), Registration::STATUS_CANCELLED);

        $this->loginAs($user);
        $this->client->jsonRequest('DELETE', sprintf('/api/v1/registrations/%s', $registration->getId()));
        self::assertResponseStatusCodeSame(404);
    }

    public function testCancellationBlockedWhenEventInProgress(): void
    {
        $user = $this->createUser('user@example.org');
        $event = $this->createEvent(Event::STATUS_IN_PROGRESS);
        $registration = $this->createRegistration($event->getId(), $user->getId());

        $this->loginAs($user);
        $this->client->jsonRequest('DELETE', sprintf('/api/v1/registrations/%s', $registration->getId()));
        self::assertResponseStatusCodeSame(422);

        $response = $this->decodedJsonResponse();
        $error = $response['error'];
        self::assertIsArray($error);
        self::assertSame('cancellation_not_allowed', $error['code']);
    }

    public function testCancellationBlockedWhenEventCompleted(): void
    {
        $user = $this->createUser('user@example.org');
        $event = $this->createEvent(Event::STATUS_COMPLETED);
        $registration = $this->createRegistration($event->getId(), $user->getId());

        $this->loginAs($user);
        $this->client->jsonRequest('DELETE', sprintf('/api/v1/registrations/%s', $registration->getId()));
        self::assertResponseStatusCodeSame(422);

        $response = $this->decodedJsonResponse();
        $error = $response['error'];
        self::assertIsArray($error);
        self::assertSame('cancellation_not_allowed', $error['code']);
    }

    public function testSuccessfulCancellationMarksCancelledAndReleasesSeat(): void
    {
        $user = $this->createUser('user@example.org');
        $event = $this->createEvent(Event::STATUS_PUBLISHED);
        $registration = $this->createRegistration($event->getId(), $user->getId());

        $this->loginAs($user);
        $this->client->jsonRequest('DELETE', sprintf('/api/v1/registrations/%s', $registration->getId()));
        self::assertResponseStatusCodeSame(200);

        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertSame('cancelled', $data['outcome']);

        $this->entityManager->clear();

        $refreshedReg = $this->entityManager->find(Registration::class, $registration->getId());
        self::assertInstanceOf(Registration::class, $refreshedReg);
        self::assertSame(Registration::STATUS_CANCELLED, $refreshedReg->getStatus());

        $refreshedReg2 = $this->entityManager->find(Registration::class, $registration->getId());
        self::assertInstanceOf(Registration::class, $refreshedReg2);
        self::assertSame(Registration::STATUS_CANCELLED, $refreshedReg2->getStatus());
    }

    private function createEvent(string $status): Event
    {
        $now = new \DateTimeImmutable('2026-05-01T10:00:00+00:00');
        $event = new Event(
            bin2hex(random_bytes(16)),
            'Spring Sync 2027',
            'Une session Archipelago.',
            $status,
            new \DateTimeImmutable('2027-05-31T10:00:00+00:00'),
            new \DateTimeImmutable('2027-05-31T22:00:00+00:00'),
            'Clermont-Ferrand',
            48,
            new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            new \DateTimeImmutable('2027-05-01T00:00:00+00:00'),
            true,
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

    private function createRegistration(
        string $eventId,
        string $userId,
        string $status = Registration::STATUS_RESERVED,
    ): Registration {
        $now = new \DateTimeImmutable('2026-05-01T10:00:00+00:00');
        $registration = new Registration(
            bin2hex(random_bytes(16)),
            $eventId,
            $userId,
            $status,
            $now,
            $now,
        );

        $this->entityManager->persist($registration);
        $this->entityManager->flush();

        return $registration;
    }

    private function createUser(string $email): User
    {
        $now = new \DateTimeImmutable('2026-05-01T10:00:00+00:00');
        $user = new User(
            bin2hex(random_bytes(16)),
            $email,
            mb_strtolower($email),
            null,
            'test-password-hash',
            ['ROLE_USER'],
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
