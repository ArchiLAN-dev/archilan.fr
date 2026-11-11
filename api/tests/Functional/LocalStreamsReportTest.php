<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Events\Domain\Entity\Event;
use App\Registrations\Domain\Entity\Registration;
use App\Streaming\Application\Service\LocalStreamsState;
use App\Streaming\Application\Support\MediaPublishCredentials;
use App\Streaming\Domain\Entity\LocalStream;
use App\Streaming\Infrastructure\Doctrine\DoctrineLocalStreamRepository;
use Symfony\Component\Clock\NativeClock;

/** Story 47.6: the régie agent reports the streams online on the LAN media server. */
final class LocalStreamsReportTest extends FunctionalTestCase
{
    public function testASignedReportReplacesTheStateAndIgnoresUnknownPaths(): void
    {
        [$event, $alice, $bob] = $this->scenario();

        $this->report($event, ['streams' => [
            ['path' => $this->path($alice), 'onlineSince' => '2020-01-01T00:00:00Z'],
            ['path' => $this->path($bob)],
            ['path' => 'p-0123456789abcdef0123'],
            ['path' => 'regie'],
        ]]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['live' => 2, 'ignored' => 2], $this->data());

        $state = $this->state()->forEvent($event->getId());
        self::assertNotNull($state['agentReportedAt']);
        self::assertSame([true, true], array_column($state['streams'], 'live'));
        $aliceSince = $this->streamOf($state, $alice)['onlineSince'];
        self::assertSame('2020-01-01T00:00:00+00:00', $aliceSince);

        // Bob is cut; Alice keeps her start even though MediaMTX now says another one.
        $this->report($event, ['streams' => [['path' => $this->path($alice), 'onlineSince' => '2026-01-01T00:00:00Z']]]);
        self::assertResponseStatusCodeSame(200);
        $state = $this->state()->forEvent($event->getId());
        self::assertTrue($this->streamOf($state, $alice)['live']);
        self::assertSame($aliceSince, $this->streamOf($state, $alice)['onlineSince']);
        self::assertFalse($this->streamOf($state, $bob)['live']);
        self::assertNull($this->streamOf($state, $bob)['onlineSince']);

        $this->report($event, ['streams' => []]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame([false, false], array_column($this->state()->forEvent($event->getId())['streams'], 'live'));
    }

    public function testAWrongMissingOrStaleSignatureIsRefused(): void
    {
        [$event] = $this->scenario();
        $body = '{"streams":[]}';
        $url = sprintf('/api/v1/events/%s/local-streams', $event->getId());

        $this->client->request('PUT', $url, server: ['CONTENT_TYPE' => 'application/json'], content: $body);
        self::assertResponseStatusCodeSame(401);

        $this->send($url, $body, (string) time(), str_repeat('0', 64));
        self::assertResponseStatusCodeSame(401);

        $stale = (string) (time() - 300);
        $this->send($url, $body, $stale, $this->sign($event, $stale, $body));
        self::assertResponseStatusCodeSame(401);

        // Another event's secret does not open this one.
        [$other] = $this->scenario(title: 'Autre LAN', email: 'x');
        $now = (string) time();
        $this->send($url, $body, $now, $this->sign($other, $now, $body));
        self::assertResponseStatusCodeSame(401);
    }

    public function testAnUnknownEventOrOneWithoutMediaServerOrABadBodyIsRefused(): void
    {
        [$event] = $this->scenario();
        // Set up before any request: a request resets the entity manager and detaches these entities.
        [$withoutServer] = $this->scenario(title: 'Sans serveur', email: 'y');
        $withoutServer->pointMediaServer(null, new \DateTimeImmutable());
        $this->entityManager->flush();

        $this->send('/api/v1/events/unknown/local-streams', '{"streams":[]}', (string) time(), str_repeat('0', 64));
        self::assertResponseStatusCodeSame(404);

        $this->report($event, ['streams' => 'p-0123456789abcdef0123']);
        self::assertResponseStatusCodeSame(422);

        $this->report($withoutServer, ['streams' => []]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testWithoutAReportForThirtySecondsNothingIsLive(): void
    {
        [$event, $alice] = $this->scenario();
        $past = new \DateTimeImmutable('-1 minute');
        $stream = new LocalStream(bin2hex(random_bytes(16)), $event->getId(), $alice->getId(), $this->path($alice), $past);
        $stream->seenOnline(null, $past);
        $this->entityManager->persist($stream);
        $this->entityManager->flush();

        $state = $this->state()->forEvent($event->getId());
        self::assertNull($state['agentReportedAt']);
        self::assertFalse($state['streams'][0]['live']);
        self::assertNull($state['streams'][0]['onlineSince']);
    }

    public function testTheAdminReadsTheAgentSecretAndOthersCannot(): void
    {
        [$event] = $this->scenario();
        $url = sprintf('/api/v1/admin/events/%s/media-agent', $event->getId());

        $this->loginAs($this->createUser('member@example.org'));
        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(403);

        $this->loginAs($this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']));
        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['eventId' => $event->getId(), 'secret' => $this->credentials()->agentSecretFor($event->getId())], $this->data());

        $this->client->request('GET', '/api/v1/admin/events/unknown/media-agent');
        self::assertResponseStatusCodeSame(404);
    }

    /** @return array{0: Event, 1: Registration, 2: Registration} */
    private function scenario(string $title = 'ArchiLAN 5', string $email = ''): array
    {
        $now = new \DateTimeImmutable();
        $event = $this->createEvent($title, $now->modify('+2 hours'), $now->modify('+14 hours'), published: true);
        $event->pointMediaServer('media.lan.archilan.fr', $now);
        $registrations = [];
        foreach (['alice', 'bob'] as $name) {
            $user = $this->createUser(sprintf('%s%s@example.org', $name, $email));
            $registration = new Registration(bin2hex(random_bytes(16)), $event->getId(), $user->getId(), Registration::STATUS_RESERVED, $now, $now);
            $registration->confirm($now);
            $this->entityManager->persist($registration);
            $registrations[] = $registration;
        }
        $this->entityManager->flush();

        return [$event, $registrations[0], $registrations[1]];
    }

    /** @param array<string, mixed> $payload */
    private function report(Event $event, array $payload): void
    {
        $body = json_encode($payload, \JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        $this->send(sprintf('/api/v1/events/%s/local-streams', $event->getId()), $body, $timestamp, $this->sign($event, $timestamp, $body));
    }

    private function send(string $url, string $body, string $timestamp, string $signature): void
    {
        $this->client->request('PUT', $url, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_ARCHILAN_TIMESTAMP' => $timestamp,
            'HTTP_X_ARCHILAN_SIGNATURE' => $signature,
        ], content: $body);
    }

    private function sign(Event $event, string $timestamp, string $body): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$body, $this->credentials()->agentSecretFor($event->getId()));
    }

    /**
     * @param array{streams: list<array{registrationId: string, path: string, live: bool, onlineSince: string|null, lastSeenAt: string}>} $state
     *
     * @return array{registrationId: string, path: string, live: bool, onlineSince: string|null, lastSeenAt: string}
     */
    private function streamOf(array $state, Registration $registration): array
    {
        foreach ($state['streams'] as $stream) {
            if ($stream['registrationId'] === $registration->getId()) {
                return $stream;
            }
        }
        self::fail('No stream for this registration.');
    }

    private function path(Registration $registration): string
    {
        return $this->credentials()->pathFor($registration->getId());
    }

    private function credentials(): MediaPublishCredentials
    {
        $credentials = self::getContainer()->get(MediaPublishCredentials::class);
        self::assertInstanceOf(MediaPublishCredentials::class, $credentials);

        return $credentials;
    }

    private function state(): LocalStreamsState
    {
        // A private service, built by hand. Cleared: the rows the requests wrote, not the ones this entity manager
        // loaded before.
        $this->entityManager->clear();

        return new LocalStreamsState(new DoctrineLocalStreamRepository($this->entityManager), new NativeClock());
    }

    /** @return array<mixed> */
    private function data(): array
    {
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);

        return $data;
    }
}
