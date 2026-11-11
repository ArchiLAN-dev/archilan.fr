<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Events\Domain\Entity\Event;
use App\Identity\Domain\Entity\User;
use App\Registrations\Domain\Entity\Registration;
use App\Streaming\Application\Support\MediaPublishCredentials;

/** Story 47.5: the LAN media server lets in the confirmed registrants of an event, each with their own key. */
final class MediaPublishTest extends FunctionalTestCase
{
    private const string HOST = 'media.lan.archilan.fr';

    public function testAConfirmedRegistrantPublishesWithTheirKeyByWhipOrSrt(): void
    {
        [$event, $registration] = $this->scenario();

        $this->auth(['token' => $this->key($event, $registration), 'path' => $this->path($registration)]);
        self::assertResponseStatusCodeSame(200);

        $this->auth(['user' => 'archilan', 'password' => $this->key($event, $registration), 'path' => $this->path($registration), 'protocol' => 'srt']);
        self::assertResponseStatusCodeSame(200);
    }

    public function testAWrongKeyAReadOrAnUnknownPathIsRefused(): void
    {
        [$event, $registration] = $this->scenario();
        $key = $this->key($event, $registration);

        foreach ([
            ['token' => 'wrong', 'path' => $this->path($registration)],
            ['token' => '', 'path' => $this->path($registration)],
            ['token' => $key, 'path' => $this->path($registration), 'action' => 'read'],
            ['token' => $key, 'path' => 'p-0123456789abcdef0123'],
            ['token' => $key, 'path' => 'regie'],
        ] as $overrides) {
            $this->auth($overrides);
            self::assertResponseStatusCodeSame(401);
        }

        $this->client->request('POST', '/api/v1/media-auth', server: ['CONTENT_TYPE' => 'application/json'], content: 'not json');
        self::assertResponseStatusCodeSame(401);
    }

    public function testTheRegistrantWhoRefusedTheBroadcastOrIsNotConfirmedIsRefused(): void
    {
        [$event, $registration] = $this->scenario();
        $registration->chooseBroadcastConsent(false, new \DateTimeImmutable());
        $this->entityManager->flush();

        $this->auth(['token' => $this->key($event, $registration), 'path' => $this->path($registration)]);
        self::assertResponseStatusCodeSame(401);

        [$otherEvent, $unconfirmed] = $this->scenario(confirmed: false, email: 'pending@example.org');
        $this->auth(['token' => $this->key($otherEvent, $unconfirmed), 'path' => $this->path($unconfirmed)]);
        self::assertResponseStatusCodeSame(401);
    }

    public function testABannedMemberIsRefused(): void
    {
        [$event, $registration, $user] = $this->scenario();
        $user->ban('Comportement', new \DateTimeImmutable());
        $this->entityManager->flush();

        $this->auth(['token' => $this->key($event, $registration), 'path' => $this->path($registration)]);
        self::assertResponseStatusCodeSame(401);
    }

    public function testOutsideTheWindowOrWithoutAMediaServerNothingGoesThrough(): void
    {
        [$farEvent, $farRegistration] = $this->scenario(startsIn: '+3 days');
        $this->auth(['token' => $this->key($farEvent, $farRegistration), 'path' => $this->path($farRegistration)]);
        self::assertResponseStatusCodeSame(401);

        [$event, $registration] = $this->scenario(email: 'other@example.org');
        $event->pointMediaServer(null, new \DateTimeImmutable());
        $this->entityManager->flush();
        $this->auth(['token' => $this->key($event, $registration), 'path' => $this->path($registration)]);
        self::assertResponseStatusCodeSame(401);
    }

    public function testTheRegistrantReadsTheirAddressesAndGetsANewKey(): void
    {
        [$event, $registration, $user] = $this->scenario();
        $this->loginAs($user);

        $this->client->request('GET', sprintf('/api/v1/registrations/%s/media-publish', $registration->getId()));
        self::assertResponseStatusCodeSame(200);
        $view = $this->data();
        self::assertTrue($view['available']);
        self::assertSame($this->key($event, $registration), $view['key']);
        self::assertSame(sprintf('https://%s:8889/%s/whip', self::HOST, $this->path($registration)), $view['whipUrl']);
        self::assertIsString($view['srtUrl']);
        self::assertStringContainsString(':archilan:'.$view['key'], $view['srtUrl']);

        $this->client->request('POST', sprintf('/api/v1/registrations/%s/media-publish/regenerate', $registration->getId()));
        self::assertResponseStatusCodeSame(200);
        $renewed = $this->data();
        self::assertNotSame($view['key'], $renewed['key']);

        // The old key no longer opens the media server, the new one does.
        self::assertIsString($view['key']);
        self::assertIsString($renewed['key']);
        $this->auth(['token' => $view['key'], 'path' => $this->path($registration)]);
        self::assertResponseStatusCodeSame(401);
        $this->auth(['token' => $renewed['key'], 'path' => $this->path($registration)]);
        self::assertResponseStatusCodeSame(200);
    }

    public function testTheRegistrantSeesWhenTheRegieReceivesTheirStream(): void
    {
        [$event, $registration, $user] = $this->scenario();
        $this->loginAs($user);
        $url = sprintf('/api/v1/registrations/%s/media-publish', $registration->getId());

        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(200);
        self::assertFalse($this->data()['live']);

        // Story 47.6: the régie agent reports the stream online.
        $body = json_encode(['streams' => [['path' => $this->path($registration)]]], \JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        $this->client->request('PUT', sprintf('/api/v1/events/%s/local-streams', $event->getId()), server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_ARCHILAN_TIMESTAMP' => $timestamp,
            'HTTP_X_ARCHILAN_SIGNATURE' => hash_hmac('sha256', $timestamp.'.'.$body, $this->credentials()->agentSecretFor($event->getId())),
        ], content: $body);
        self::assertResponseStatusCodeSame(200);

        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(200);
        self::assertTrue($this->data()['live']);
    }

    public function testWithoutAccessTheRegistrantLearnsWhyAndAnotherMemberIsTurnedAway(): void
    {
        [, $registration, $user] = $this->scenario(startsIn: '+3 days');
        $this->loginAs($user);

        $this->client->request('GET', sprintf('/api/v1/registrations/%s/media-publish', $registration->getId()));
        self::assertResponseStatusCodeSame(200);
        $view = $this->data();
        self::assertFalse($view['available']);
        self::assertSame('outside_window', $view['reason']);
        self::assertNull($view['key']);
        self::assertIsString($view['opensAt']);

        $this->loginAs($this->createUser('intruder@example.org'));
        $this->client->request('GET', sprintf('/api/v1/registrations/%s/media-publish', $registration->getId()));
        self::assertResponseStatusCodeSame(403);
    }

    public function testTheAdminSetsTheMediaServerAndASaveWithoutItKeepsIt(): void
    {
        $event = $this->createEvent('Spring Sync 2027', new \DateTimeImmutable('2027-05-31T10:00:00+00:00'), new \DateTimeImmutable('2027-05-31T22:00:00+00:00'), published: true);
        $this->loginAs($this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']));
        $payload = [
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
        $url = sprintf('/api/v1/admin/events/%s', $event->getId());

        $this->client->jsonRequest('PATCH', $url, [...$payload, 'mediaServerHost' => ' Media.LAN.archilan.fr ']);
        self::assertResponseIsSuccessful();
        self::assertSame(self::HOST, $this->data()['mediaServerHost']);

        $this->client->jsonRequest('PATCH', $url, $payload);
        self::assertResponseIsSuccessful();
        self::assertSame(self::HOST, $this->data()['mediaServerHost']);

        $this->client->jsonRequest('PATCH', $url, [...$payload, 'mediaServerHost' => 'https://media.lan.archilan.fr:8889']);
        self::assertResponseStatusCodeSame(422);

        $this->client->jsonRequest('PATCH', $url, [...$payload, 'mediaServerHost' => '']);
        self::assertResponseIsSuccessful();
        self::assertNull($this->data()['mediaServerHost']);
    }

    /**
     * An event starting soon (inside the window by default) with a media server, and one registrant.
     *
     * @return array{0: Event, 1: Registration, 2: User}
     */
    private function scenario(string $startsIn = '+2 hours', bool $confirmed = true, string $email = 'player@example.org'): array
    {
        $now = new \DateTimeImmutable();
        $event = $this->createEvent('ArchiLAN 5', $now->modify($startsIn), $now->modify($startsIn)->modify('+12 hours'), published: true);
        $event->pointMediaServer(self::HOST, $now);
        $user = $this->createUser($email);
        $registration = new Registration(bin2hex(random_bytes(16)), $event->getId(), $user->getId(), Registration::STATUS_RESERVED, $now, $now);
        if ($confirmed) {
            $registration->confirm($now);
        }
        $this->entityManager->persist($registration);
        $this->entityManager->flush();

        return [$event, $registration, $user];
    }

    /** @param array<string, string> $overrides */
    private function auth(array $overrides): void
    {
        $this->client->jsonRequest('POST', '/api/v1/media-auth', [
            'ip' => '192.168.1.20',
            'user' => '',
            'password' => '',
            'token' => '',
            'action' => 'publish',
            'path' => '',
            'protocol' => 'webrtc',
            'id' => null,
            'query' => '',
            'userAgent' => 'OBS/31.1.2',
            ...$overrides,
        ]);
    }

    private function credentials(): MediaPublishCredentials
    {
        $credentials = self::getContainer()->get(MediaPublishCredentials::class);
        self::assertInstanceOf(MediaPublishCredentials::class, $credentials);

        return $credentials;
    }

    private function path(Registration $registration): string
    {
        return $this->credentials()->pathFor($registration->getId());
    }

    private function key(Event $event, Registration $registration): string
    {
        $this->entityManager->refresh($registration);

        return $this->credentials()->keyFor($event->getId(), $registration->getId(), $registration->getMediaKeyVersion());
    }

    /** @return array<mixed> */
    private function data(): array
    {
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);

        return $data;
    }
}
