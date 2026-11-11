<?php

declare(strict_types=1);

namespace App\Tests\Functional;

/** Story 53.7: the member's registrations carry what the "Mon event" entry of the account menu needs. */
final class AccountRegistrationsTest extends FunctionalTestCase
{
    public function testARegistrationCarriesTheEventDatesAndStatus(): void
    {
        $member = $this->createUser('member@example.org');
        $event = $this->createEvent('ArchiLAN 4', new \DateTimeImmutable('2027-06-05T09:00:00+00:00'), new \DateTimeImmutable('2027-06-06T18:00:00+00:00'), published: true);
        $registration = $this->createRegistration($event->getId(), $member->getId());
        $this->loginAs($member);

        $this->client->request('GET', '/api/v1/account/registrations');

        self::assertResponseIsSuccessful();
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);
        self::assertCount(1, $data);
        self::assertIsArray($data[0]);
        self::assertSame($registration->getId(), $data[0]['registrationId']);
        self::assertSame($event->getId(), $data[0]['eventSlug']);
        self::assertSame('2027-06-05T09:00:00+00:00', $data[0]['eventStartsAt']);
        self::assertSame('2027-06-06T18:00:00+00:00', $data[0]['eventEndsAt']);
        self::assertSame('published', $data[0]['eventStatus']);
    }
}
