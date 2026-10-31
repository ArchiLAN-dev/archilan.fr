<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Events\Domain\Entity\Event;
use App\Registrations\Domain\Entity\Registration;

/** Story 47.1: the member lets the ArchiLAN broadcast show their stream, on their own registration. */
final class BroadcastConsentTest extends FunctionalTestCase
{
    public function testTheMemberOptsOutThenBackIn(): void
    {
        $event = $this->createEvent('ArchiLAN #4', new \DateTimeImmutable('2026-11-13 18:00'), new \DateTimeImmutable('2026-11-15 18:00'), published: true);
        $member = $this->createUser('member@example.org');
        $registration = $this->createRegistration($event->getId(), $member->getId());
        $url = sprintf('/api/v1/registrations/%s/broadcast-consent', $registration->getId());
        $this->loginAs($member);

        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['consent' => true, 'editable' => true], $this->decodedJsonResponse()['data']);

        $this->client->jsonRequest('PUT', $url, ['consent' => false]);
        self::assertResponseStatusCodeSame(200);
        $this->client->request('GET', $url);
        self::assertSame(['consent' => false, 'editable' => true], $this->decodedJsonResponse()['data']);

        $this->client->jsonRequest('PUT', $url, ['consent' => true]);
        self::assertResponseStatusCodeSame(200);
        $this->client->request('GET', $url);
        self::assertSame(['consent' => true, 'editable' => true], $this->decodedJsonResponse()['data']);
    }

    public function testAClosedChoiceAndABadBodyAreRefused(): void
    {
        $event = $this->createEvent('ArchiLAN #4', new \DateTimeImmutable('2026-11-13 18:00'), new \DateTimeImmutable('2026-11-15 18:00'), published: true);
        $member = $this->createUser('member@example.org');
        $cancelled = $this->createRegistration($event->getId(), $member->getId(), Registration::STATUS_CANCELLED);
        $this->loginAs($member);

        $this->client->jsonRequest('PUT', sprintf('/api/v1/registrations/%s/broadcast-consent', $cancelled->getId()), ['consent' => true]);
        self::assertResponseStatusCodeSame(422);

        $this->client->jsonRequest('PUT', sprintf('/api/v1/registrations/%s/broadcast-consent', $cancelled->getId()), ['consent' => 'yes']);
        self::assertResponseStatusCodeSame(400);

        $over = $this->createEvent('ArchiLAN #3', new \DateTimeImmutable('2025-11-07 18:00'), new \DateTimeImmutable('2025-11-09 18:00'), published: true);
        $registration = $this->createRegistration($over->getId(), $member->getId());
        $over->transitionTo(Event::STATUS_IN_PROGRESS, new \DateTimeImmutable('2025-11-07 18:00'));
        $over->transitionTo(Event::STATUS_COMPLETED, new \DateTimeImmutable('2025-11-09 18:00'));
        $this->entityManager->flush();
        $url = sprintf('/api/v1/registrations/%s/broadcast-consent', $registration->getId());

        $this->client->request('GET', $url);
        self::assertSame(['consent' => true, 'editable' => false], $this->decodedJsonResponse()['data']);
        $this->client->jsonRequest('PUT', $url, ['consent' => true]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testOnlyTheMemberSeesOrChangesIt(): void
    {
        $event = $this->createEvent('ArchiLAN #4', new \DateTimeImmutable('2026-11-13 18:00'), new \DateTimeImmutable('2026-11-15 18:00'), published: true);
        $member = $this->createUser('member@example.org');
        $registration = $this->createRegistration($event->getId(), $member->getId());
        $url = sprintf('/api/v1/registrations/%s/broadcast-consent', $registration->getId());

        $this->client->jsonRequest('PUT', $url, ['consent' => true]);
        self::assertResponseStatusCodeSame(401);

        $this->loginAs($this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']));
        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(404);
        $this->client->jsonRequest('PUT', $url, ['consent' => true]);
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/api/v1/registrations/unknown/broadcast-consent');
        self::assertResponseStatusCodeSame(404);
    }
}
