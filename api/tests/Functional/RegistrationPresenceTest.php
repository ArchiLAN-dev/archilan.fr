<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Domain\Entity\User;
use App\Registrations\Application\Query\PresenceTimelineQueryInterface;

/** Story 52.2: when a member is at the event, on their registration. */
final class RegistrationPresenceTest extends FunctionalTestCase
{
    private User $admin;
    private User $member;
    private string $eventId;
    private string $registrationId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Jean');
        $this->member = $this->createUser('member@example.org', ['ROLE_USER'], 'Zoé');
        $this->eventId = $this->createEvent(
            'ArchiLAN 4',
            new \DateTimeImmutable('2027-06-04T16:00:00+00:00'),
            new \DateTimeImmutable('2027-06-06T18:00:00+00:00'),
        )->getId();
        $this->registrationId = $this->createRegistration($this->eventId, $this->member->getId())->getId();
    }

    public function testTheMemberSetsReadsAndClearsTheirWindows(): void
    {
        $this->loginAs($this->member);
        $this->putWindows('/api/v1/registrations/'.$this->registrationId.'/presence', [
            ['startsAt' => '2027-06-05T10:00:00.000Z', 'endsAt' => '2027-06-06T12:00:00.000Z'],
            ['startsAt' => '2027-06-04T18:30:42+02:00', 'endsAt' => '2027-06-04T23:00:00+02:00'],
        ]);
        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', '/api/v1/registrations/'.$this->registrationId.'/presence');
        self::assertResponseIsSuccessful();
        $data = $this->data();
        self::assertSame([
            ['startsAt' => '2027-06-04T16:30:00+00:00', 'endsAt' => '2027-06-04T21:00:00+00:00'],
            ['startsAt' => '2027-06-05T10:00:00+00:00', 'endsAt' => '2027-06-06T12:00:00+00:00'],
        ], $data['windows']);
        self::assertTrue($data['editable']);
        self::assertSame('2027-06-04T16:00:00+00:00', $data['eventStartsAt']);

        $this->putWindows('/api/v1/registrations/'.$this->registrationId.'/presence', []);
        self::assertResponseStatusCodeSame(204);
        $this->client->request('GET', '/api/v1/registrations/'.$this->registrationId.'/presence');
        self::assertSame([], $this->data()['windows']);
    }

    public function testBrokenRulesAndBadBodiesAreRefused(): void
    {
        $this->loginAs($this->member);
        $url = '/api/v1/registrations/'.$this->registrationId.'/presence';

        $this->putWindows($url, [['startsAt' => '2027-06-04T10:00:00Z', 'endsAt' => '2027-06-04T20:00:00Z']]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('presence_window_outside_event', $this->errorCode());

        $this->putWindows($url, [
            ['startsAt' => '2027-06-05T10:00:00Z', 'endsAt' => '2027-06-05T20:00:00Z'],
            ['startsAt' => '2027-06-05T19:00:00Z', 'endsAt' => '2027-06-05T22:00:00Z'],
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('presence_window_overlap', $this->errorCode());

        $this->putWindows($url, [['startsAt' => 'demain', 'endsAt' => '2027-06-05T22:00:00Z']]);
        self::assertResponseStatusCodeSame(400);
    }

    public function testOnlyTheOwnerOrAnAdminTouchesTheWindows(): void
    {
        $other = $this->createUser('other@example.org');
        $this->loginAs($other);
        $this->client->request('GET', '/api/v1/registrations/'.$this->registrationId.'/presence');
        self::assertResponseStatusCodeSame(404);
        $this->putWindows('/api/v1/registrations/'.$this->registrationId.'/presence', []);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/api/v1/admin/events/'.$this->eventId.'/presence');
        self::assertResponseStatusCodeSame(403);

        $this->loginAs($this->admin);
        $this->putWindows('/api/v1/admin/events/'.$this->eventId.'/registrations/'.$this->registrationId.'/presence', [
            ['startsAt' => '2027-06-05T08:00:00Z', 'endsAt' => '2027-06-05T20:00:00Z'],
        ]);
        self::assertResponseStatusCodeSame(204);
        $this->client->request('GET', '/api/v1/admin/events/'.$this->eventId.'/registrations/'.$this->registrationId.'/presence');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->rows($this->data()['windows']));

        $elsewhere = $this->createEvent('Autre', new \DateTimeImmutable('2027-07-01T09:00:00+00:00'), new \DateTimeImmutable('2027-07-02T18:00:00+00:00'))->getId();
        $this->client->request('GET', '/api/v1/admin/events/'.$elsewhere.'/registrations/'.$this->registrationId.'/presence');
        self::assertResponseStatusCodeSame(404);
    }

    public function testTheTimelineListsConfirmedRegistrantsAndTheAwayCheckFollowsTheWindows(): void
    {
        $alice = $this->createUser('alice@example.org', ['ROLE_USER'], 'alice');
        $aliceRegistration = $this->createRegistration($this->eventId, $alice->getId())->getId();
        $this->createRegistration($this->eventId, $this->createUser('pending@example.org')->getId());

        foreach ([[$this->member, $this->registrationId], [$alice, $aliceRegistration]] as [$user, $registrationId]) {
            $this->loginAs($user);
            $this->client->jsonRequest('POST', '/api/v1/registrations/'.$registrationId.'/submit');
            self::assertResponseIsSuccessful();
        }
        $this->loginAs($this->member);
        $this->putWindows('/api/v1/registrations/'.$this->registrationId.'/presence', [
            ['startsAt' => '2027-06-05T10:00:00Z', 'endsAt' => '2027-06-06T12:00:00Z'],
        ]);

        $this->loginAs($this->admin);
        $this->client->request('GET', '/api/v1/admin/events/'.$this->eventId.'/presence');
        self::assertResponseIsSuccessful();
        $registrants = $this->rows($this->data()['registrants']);
        self::assertSame(['alice', 'Zoé'], array_column($registrants, 'displayName'));
        self::assertSame([], $registrants[0]['windows']);
        self::assertCount(1, $this->rows($registrants[1]['windows']));

        $query = self::getContainer()->get(PresenceTimelineQueryInterface::class);
        self::assertInstanceOf(PresenceTimelineQueryInterface::class, $query);
        self::assertTrue($query->isOutsideWindows($this->registrationId, new \DateTimeImmutable('2027-06-05T09:59:00+00:00')));
        self::assertFalse($query->isOutsideWindows($this->registrationId, new \DateTimeImmutable('2027-06-05T10:00:00+00:00')));
        self::assertTrue($query->isOutsideWindows($this->registrationId, new \DateTimeImmutable('2027-06-06T12:00:00+00:00')));
        self::assertFalse($query->isOutsideWindows($aliceRegistration, new \DateTimeImmutable('2027-06-05T03:00:00+00:00')));
    }

    /** @param list<array{startsAt: string, endsAt: string}> $windows */
    private function putWindows(string $url, array $windows): void
    {
        $this->client->jsonRequest('PUT', $url, ['windows' => $windows]);
    }

    /** @return array<mixed> */
    private function data(): array
    {
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);

        return $data;
    }

    /** @return list<array<mixed>> */
    private function rows(mixed $value): array
    {
        self::assertIsArray($value);
        $rows = [];
        foreach ($value as $row) {
            self::assertIsArray($row);
            $rows[] = $row;
        }

        return $rows;
    }

    private function errorCode(): string
    {
        $error = $this->decodedJsonResponse()['error'] ?? null;
        self::assertIsArray($error);
        self::assertIsString($error['code'] ?? null);

        return $error['code'];
    }
}
