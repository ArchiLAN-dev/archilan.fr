<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Events\Domain\Entity\Event;
use App\Identity\Domain\Entity\User;
use App\Membership\Domain\Entity\Membership;

/** Story 4.21: a private event is open to an active adherent, or to a member who gave its access code. */
final class PrivateEventRegistrationTest extends FunctionalTestCase
{
    private const string CODE = 'archilan-4';

    public function testAnActiveAdherentRegistersWithoutACode(): void
    {
        $user = $this->createUser('adherent@example.org');
        $this->giveMembership($user, expired: false);
        $this->loginAs($user);
        $event = $this->makePrivateEvent();

        self::assertNull($this->eligibilityReason($event));
        $this->client->jsonRequest('POST', sprintf('/api/v1/events/%s/registrations', $event->getId()));
        self::assertResponseStatusCodeSame(201);
    }

    public function testAMemberWhoGaveTheCodeRegisters(): void
    {
        $user = $this->createUser('guest@example.org');
        $this->loginAs($user);
        $event = $this->makePrivateEvent();

        $this->client->jsonRequest('POST', sprintf('/api/v1/events/%s/verify-private-access', $event->getId()), ['password' => self::CODE]);
        self::assertResponseIsSuccessful();

        self::assertNull($this->eligibilityReason($event));
        $this->client->jsonRequest('POST', sprintf('/api/v1/events/%s/registrations', $event->getId()));
        self::assertResponseStatusCodeSame(201);
    }

    public function testAWrongCodeDoesNotOpenTheEvent(): void
    {
        $user = $this->createUser('guest@example.org');
        $this->loginAs($user);
        $event = $this->makePrivateEvent();

        $this->client->jsonRequest('POST', sprintf('/api/v1/events/%s/verify-private-access', $event->getId()), ['password' => 'nope']);
        self::assertResponseIsSuccessful();

        $this->assertRefused($event);
    }

    public function testAnExpiredAdherentNeedsTheCode(): void
    {
        $user = $this->createUser('former@example.org');
        $this->giveMembership($user, expired: true);
        $this->loginAs($user);

        $this->assertRefused($this->makePrivateEvent());
    }

    public function testTheCodeOfAnotherEventDoesNotCount(): void
    {
        $user = $this->createUser('guest@example.org');
        $this->loginAs($user);
        $other = $this->makePrivateEvent('Autre LAN');
        $event = $this->makePrivateEvent();

        $this->client->jsonRequest('POST', sprintf('/api/v1/events/%s/verify-private-access', $other->getId()), ['password' => self::CODE]);
        self::assertResponseIsSuccessful();

        $this->assertRefused($event);
    }

    private function assertRefused(Event $event): void
    {
        self::assertSame('private_event', $this->eligibilityReason($event));

        $this->client->jsonRequest('POST', sprintf('/api/v1/events/%s/registrations', $event->getId()));
        self::assertResponseStatusCodeSame(422);
        $error = $this->decodedJsonResponse()['error'];
        self::assertIsArray($error);
        self::assertSame('not_eligible', $error['code']);
    }

    private function eligibilityReason(Event $event): mixed
    {
        $this->client->jsonRequest('GET', sprintf('/api/v1/events/%s/registration-eligibility', $event->getId()));
        self::assertResponseIsSuccessful();
        $data = $this->decodedJsonResponse()['data'];
        self::assertIsArray($data);
        // Story 4.23: the page offers the access-code form only when the event has a code.
        self::assertIsArray($data['event']);
        self::assertTrue($data['event']['hasPrivateAccessPassword']);

        return $data['reason'];
    }

    private function giveMembership(User $user, bool $expired): void
    {
        $now = new \DateTimeImmutable();
        $membership = Membership::create($user->getId(), $now->modify('-1 month'), $now->modify('+11 months'), 'admin', null, null, $now);
        if ($expired) {
            $membership->expire($now);
        }
        $this->entityManager->persist($membership);
        $this->entityManager->flush();
    }

    private function makePrivateEvent(string $title = 'ArchiLAN 4'): Event
    {
        $now = new \DateTimeImmutable();
        $event = $this->createEvent(
            $title,
            $now->modify('+2 months'),
            $now->modify('+2 months +12 hours'),
            isPublic: false,
            registrationOpensAt: $now->modify('-1 day'),
            registrationClosesAt: $now->modify('+1 month'),
        );
        $this->transitionEventTo($event, Event::STATUS_PUBLISHED, $now);
        $event->configurePrivateAccessPassword(password_hash(self::CODE, PASSWORD_BCRYPT), $now);
        $this->entityManager->flush();

        return $event;
    }
}
