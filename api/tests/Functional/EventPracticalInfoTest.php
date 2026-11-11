<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Events\Domain\Entity\Event;
use App\Registrations\Domain\Entity\Registration;

/** Story 53.4: the practical information of an event, written by the admin and read by its registrants only. */
final class EventPracticalInfoTest extends FunctionalTestCase
{
    private const string INFO = "## Sur place\nWifi : ArchiLAN / code 1234";

    public function testTheAdminWritesThePracticalInfo(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $event = $this->event();
        $this->loginAs($admin);

        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/events/%s', $event->getId()), [
            ...$this->validPayload(),
            'practicalInfo' => '  '.self::INFO.'  ',
        ]);

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['data']);
        self::assertSame(self::INFO, $response['data']['practicalInfo']);
        self::assertIsString($response['data']['practicalInfoUpdatedAt']);
    }

    public function testASaveWithoutTheFieldKeepsIt(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $event = $this->event(self::INFO);
        $this->loginAs($admin);

        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/events/%s', $event->getId()), $this->validPayload());

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['data']);
        self::assertSame(self::INFO, $response['data']['practicalInfo']);
    }

    public function testTooLongPracticalInfoIsRefused(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $event = $this->event();
        $this->loginAs($admin);

        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/events/%s', $event->getId()), [
            ...$this->validPayload(),
            'practicalInfo' => str_repeat('a', Event::PRACTICAL_INFO_MAX_LENGTH + 1),
        ]);

        self::assertResponseStatusCodeSame(422);
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['error']);
        self::assertIsArray($response['error']['details']);
        self::assertArrayHasKey('practicalInfo', $response['error']['details']);
    }

    public function testARegistrantReadsThePracticalInfo(): void
    {
        $member = $this->createUser('member@example.org');
        $event = $this->event(self::INFO);
        $this->createRegistration($event->getId(), $member->getId());
        $this->loginAs($member);

        $this->client->jsonRequest('GET', sprintf('/api/v1/events/%s/practical-info', $event->getId()));

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['data']);
        self::assertSame(self::INFO, $response['data']['markdown']);
        self::assertIsString($response['data']['updatedAt']);
    }

    public function testAnEventWithoutPracticalInfoAnswersNull(): void
    {
        $member = $this->createUser('member@example.org');
        $event = $this->event();
        $this->createRegistration($event->getId(), $member->getId());
        $this->loginAs($member);

        $this->client->jsonRequest('GET', sprintf('/api/v1/events/%s/practical-info', $event->getId()));

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        self::assertSame(['markdown' => null, 'updatedAt' => null], $response['data']);
    }

    public function testAMemberWithoutRegistrationIsRefused(): void
    {
        $member = $this->createUser('member@example.org');
        $event = $this->event(self::INFO);
        $this->loginAs($member);

        $this->client->jsonRequest('GET', sprintf('/api/v1/events/%s/practical-info', $event->getId()));

        self::assertResponseStatusCodeSame(403);
        self::assertStringNotContainsString('1234', (string) $this->client->getResponse()->getContent());
    }

    public function testACancelledRegistrationIsRefused(): void
    {
        $member = $this->createUser('member@example.org');
        $event = $this->event(self::INFO);
        $this->createRegistration($event->getId(), $member->getId(), Registration::STATUS_CANCELLED);
        $this->loginAs($member);

        $this->client->jsonRequest('GET', sprintf('/api/v1/events/%s/practical-info', $event->getId()));

        self::assertResponseStatusCodeSame(403);
    }

    public function testAVisitorMustLogIn(): void
    {
        $event = $this->event(self::INFO);

        $this->client->jsonRequest('GET', sprintf('/api/v1/events/%s/practical-info', $event->getId()));

        self::assertResponseStatusCodeSame(401);
    }

    public function testAnAdminReadsItWithoutRegistration(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $event = $this->event(self::INFO);
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', sprintf('/api/v1/events/%s/practical-info', $event->getId()));

        self::assertResponseIsSuccessful();
    }

    public function testAnUnknownEventIsNotFound(): void
    {
        $member = $this->createUser('member@example.org');
        $this->loginAs($member);

        $this->client->jsonRequest('GET', '/api/v1/events/unknown/practical-info');

        self::assertResponseStatusCodeSame(404);
    }

    public function testThePublicEventNeverCarriesIt(): void
    {
        $event = $this->event(self::INFO);

        $this->client->jsonRequest('GET', sprintf('/api/v1/events/%s', $event->getId()));
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('1234', (string) $this->client->getResponse()->getContent());

        $this->client->jsonRequest('GET', '/api/v1/events');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('1234', (string) $this->client->getResponse()->getContent());
    }

    private function event(?string $practicalInfo = null): Event
    {
        $event = $this->createEvent('Spring Sync 2027', new \DateTimeImmutable('2027-05-31T10:00:00+00:00'), new \DateTimeImmutable('2027-05-31T22:00:00+00:00'), published: true);
        if (null !== $practicalInfo) {
            $event->writePracticalInfo($practicalInfo, new \DateTimeImmutable('2026-05-02T10:00:00+00:00'));
            $this->entityManager->flush();
        }

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
}
