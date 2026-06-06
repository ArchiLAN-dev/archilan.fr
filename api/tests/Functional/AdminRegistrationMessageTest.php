<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Events\Domain\Event;
use App\Registrations\Domain\RegistrationAdminMessage;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;

final class AdminRegistrationMessageTest extends FunctionalTestCase
{
    use MailerAssertionsTrait;

    protected function setUp(): void
    {
        parent::setUp();
    }

    public function testAdminCanSendMessageToRegistrant(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $participant = $this->createUser('participant@example.org', ['ROLE_USER']);
        $event = $this->makeEvent();
        $registration = $this->createRegistration($event->getId(), $participant->getId());
        $this->loginAs($admin);

        $this->client->jsonRequest(
            'POST',
            '/api/v1/admin/events/'.$event->getId().'/registrations/'.$registration->getId().'/messages',
            ['subject' => 'Information importante', 'body' => 'Veuillez vérifier vos options de jeu.'],
        );

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        $data = $payload['data'] ?? null;
        self::assertIsArray($data);
        self::assertSame('sent', $data['outcome']);
        self::assertIsString($data['sentAt']);

        $this->assertEmailCount(1);
        $email = $this->getMailerMessage();
        self::assertNotNull($email);
        $this->assertEmailAddressContains($email, 'to', 'participant@example.org');
        $this->assertEmailSubjectContains($email, 'Information importante');
        $this->assertEmailTextBodyContains($email, 'Veuillez vérifier vos options de jeu.');
        $history = $this->entityManager->getRepository(RegistrationAdminMessage::class)->findOneBy([
            'registrationId' => $registration->getId(),
        ]);
        self::assertInstanceOf(RegistrationAdminMessage::class, $history);
        self::assertNotSame('', $history->getId());
        self::assertSame($event->getId(), $history->getEventId());
        self::assertSame($admin->getId(), $history->getAdminId());
        self::assertSame('Information importante', $history->getSubject());
    }

    public function testReturns422WhenSubjectMissing(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $participant = $this->createUser('participant@example.org', ['ROLE_USER']);
        $event = $this->makeEvent();
        $registration = $this->createRegistration($event->getId(), $participant->getId());
        $this->loginAs($admin);

        $this->client->jsonRequest(
            'POST',
            '/api/v1/admin/events/'.$event->getId().'/registrations/'.$registration->getId().'/messages',
            ['subject' => '', 'body' => 'Some body'],
        );

        self::assertResponseStatusCodeSame(422);
    }

    public function testReturns422WhenBodyMissing(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $participant = $this->createUser('participant@example.org', ['ROLE_USER']);
        $event = $this->makeEvent();
        $registration = $this->createRegistration($event->getId(), $participant->getId());
        $this->loginAs($admin);

        $this->client->jsonRequest(
            'POST',
            '/api/v1/admin/events/'.$event->getId().'/registrations/'.$registration->getId().'/messages',
            ['subject' => 'Subject', 'body' => ''],
        );

        self::assertResponseStatusCodeSame(422);
    }

    public function testReturns404WhenRegistrationNotFound(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $event = $this->makeEvent();
        $this->loginAs($admin);

        $this->client->jsonRequest(
            'POST',
            '/api/v1/admin/events/'.$event->getId().'/registrations/nonexistent/messages',
            ['subject' => 'Subject', 'body' => 'Body'],
        );

        self::assertResponseStatusCodeSame(404);
    }

    private function makeEvent(): Event
    {
        return $this->createEvent(
            'Spring Sync 2027',
            new \DateTimeImmutable('2027-05-31T10:00:00+00:00'),
            new \DateTimeImmutable('2027-05-31T22:00:00+00:00'),
            capacity: 48,
            published: true,
        );
    }
}
