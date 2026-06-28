<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Community\Domain\Audience;
use App\Community\Domain\BannerPreset;
use App\Community\Domain\CommunityProfile;
use App\Events\Domain\Event;
use App\Identity\Domain\User;
use App\PersonalRuns\Domain\Run;
use App\PersonalRuns\Domain\RunParticipant;
use App\Registrations\Domain\Registration;
use App\Streaming\Infrastructure\TwitchApiClientInterface;
use App\WeeklyRuns\Domain\WeeklyEntry;
use App\WeeklyRuns\Domain\WeeklyRun;

final class ParticipantStreamsTest extends FunctionalTestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = new \DateTimeImmutable('2026-05-01T10:00:00+00:00');
    }

    public function testEventListsStreamersLiveFirstAndExcludesCancelledAndNonTwitch(): void
    {
        $event = $this->createEvent('LAN', $this->now, $this->now->modify('+1 day'));

        $live = $this->createTwitchMember('live@x.test', 'evlive', 'Alice', 'alice-ev');
        $offline = $this->createTwitchMember('off@x.test', 'evoff', 'Bob', 'bob-ev');
        $cancelled = $this->createTwitchMember('cancel@x.test', 'evcancel', 'Dave', 'dave-ev');
        $youtubeOnly = $this->createMemberWithLinks('yt@x.test', 'Carol', 'carol-ev', [
            ['label' => 'YouTube', 'url' => 'https://youtube.com/@carol'],
        ]);
        $noProfile = $this->createUser('noprofile@x.test', displayName: 'Erin', slug: 'erin-ev');

        $this->createRegistration($event->getId(), $live->getId());
        $this->createRegistration($event->getId(), $offline->getId());
        $this->createRegistration($event->getId(), $cancelled->getId(), Registration::STATUS_CANCELLED);
        $this->createRegistration($event->getId(), $youtubeOnly->getId());
        $this->createRegistration($event->getId(), $noProfile->getId());

        $this->fakeLive(['evlive' => 42]);

        $this->client->request('GET', '/api/v1/events/'.$event->getId().'/participant-streams');

        $this->assertResponseStatusCodeSame(200);
        $data = $this->dataList();

        self::assertCount(2, $data);
        self::assertSame('evlive', $data[0]['twitchLogin']);
        self::assertTrue($data[0]['live']);
        self::assertSame(42, $data[0]['viewerCount']);
        self::assertSame('Alice', $data[0]['displayName']);
        self::assertSame('https://cdn.test/evlive.png', $data[0]['avatarUrl']);
        self::assertSame('evoff', $data[1]['twitchLogin']);
        self::assertFalse($data[1]['live']);
        self::assertNull($data[1]['viewerCount']);
    }

    public function testBannedAndSuspendedParticipantsAreExcluded(): void
    {
        $event = $this->createEvent('LAN', $this->now, $this->now->modify('+1 day'));

        $ok = $this->createTwitchMember('ok@x.test', 'bnok', 'Okay', 'ok-bn');
        $banned = $this->createTwitchMember('banned@x.test', 'bnbanned', 'Banned', 'banned-bn');
        $banned->ban('spam', $this->now);
        $suspended = $this->createTwitchMember('susp@x.test', 'bnsusp', 'Suspended', 'susp-bn');
        // Far-future so the suspension is still active against the read layer's real-clock check.
        $suspended->suspendUntil(new \DateTimeImmutable('2099-01-01T00:00:00+00:00'), 'cooldown', $this->now);
        $this->entityManager->flush();

        $this->createRegistration($event->getId(), $ok->getId());
        $this->createRegistration($event->getId(), $banned->getId());
        $this->createRegistration($event->getId(), $suspended->getId());

        $this->fakeLive([]);

        $this->client->request('GET', '/api/v1/events/'.$event->getId().'/participant-streams');

        $this->assertResponseStatusCodeSame(200);
        $data = $this->dataList();

        self::assertCount(1, $data);
        self::assertSame('bnok', $data[0]['twitchLogin']);
    }

    public function testExpiredSuspensionIsIncluded(): void
    {
        $event = $this->createEvent('LAN', $this->now, $this->now->modify('+1 day'));

        $member = $this->createTwitchMember('expired@x.test', 'exok', 'Expired', 'exok-bn');
        // Suspension already over relative to the read layer's real-clock check - must be included.
        $member->suspendUntil(new \DateTimeImmutable('2020-01-01T00:00:00+00:00'), 'old', $this->now);
        $this->entityManager->flush();

        $this->createRegistration($event->getId(), $member->getId());

        $this->fakeLive([]);

        $this->client->request('GET', '/api/v1/events/'.$event->getId().'/participant-streams');

        $this->assertResponseStatusCodeSame(200);
        $data = $this->dataList();

        self::assertCount(1, $data);
        self::assertSame('exok', $data[0]['twitchLogin']);
    }

    public function testPersonalRunParticipantStreams(): void
    {
        $run = Run::create('owner-id', 'My run', $this->now);
        $this->entityManager->persist($run);
        $this->entityManager->flush();

        $member = $this->createTwitchMember('runner@x.test', 'runlive', 'Runner', 'runner-rn');
        $this->entityManager->persist(RunParticipant::create($run->getId(), $member->getId(), $this->now));
        $this->entityManager->flush();

        $this->fakeLive(['runlive' => 7]);

        $this->client->request('GET', '/api/v1/runs/'.$run->getId().'/participant-streams');

        $this->assertResponseStatusCodeSame(200);
        $data = $this->dataList();

        self::assertCount(1, $data);
        self::assertSame('runlive', $data[0]['twitchLogin']);
        self::assertTrue($data[0]['live']);
        self::assertSame(7, $data[0]['viewerCount']);
    }

    public function testWeeklyRunDeduplicatesAttemptsPerUser(): void
    {
        $weekly = new WeeklyRun(
            bin2hex(random_bytes(16)),
            'template-id',
            2026,
            18,
            'seed',
            WeeklyRun::STATUS_ACTIVE,
            $this->now,
            $this->now,
        );
        $this->entityManager->persist($weekly);

        $member = $this->createTwitchMember('weekly@x.test', 'wklive', 'Weekly', 'weekly-wk');
        // Two attempts for the same member - must collapse to one streamer row.
        $this->entityManager->persist(new WeeklyEntry(bin2hex(random_bytes(16)), $weekly->getId(), $member->getId(), 1, $this->now, $this->now));
        $this->entityManager->persist(new WeeklyEntry(bin2hex(random_bytes(16)), $weekly->getId(), $member->getId(), 2, $this->now, $this->now));
        $this->entityManager->flush();

        $this->fakeLive(['wklive' => 3]);

        $this->client->request('GET', '/api/v1/weekly-runs/'.$weekly->getId().'/participant-streams');

        $this->assertResponseStatusCodeSame(200);
        $data = $this->dataList();

        self::assertCount(1, $data);
        self::assertSame('wklive', $data[0]['twitchLogin']);
    }

    public function testCompletedEventReturnsNoStreams(): void
    {
        $event = $this->createEvent('LAN', $this->now, $this->now->modify('+1 day'));
        $this->transitionEventTo($event, Event::STATUS_COMPLETED, $this->now);
        $this->entityManager->flush();

        $member = $this->createTwitchMember('ev-done@x.test', 'evdone', 'EvDone', 'evdone-ev');
        $this->createRegistration($event->getId(), $member->getId());

        // The member is live, but the event is over - it must not surface.
        $this->fakeLive(['evdone' => 9]);

        $this->client->request('GET', '/api/v1/events/'.$event->getId().'/participant-streams');

        $this->assertResponseStatusCodeSame(200);
        self::assertSame([], $this->dataList());
    }

    public function testFinishedWeeklyRunReturnsNoStreams(): void
    {
        $weekly = new WeeklyRun(
            bin2hex(random_bytes(16)),
            'template-id',
            2026,
            17,
            'seed',
            WeeklyRun::STATUS_FINISHED,
            $this->now,
            $this->now,
        );
        $this->entityManager->persist($weekly);

        $member = $this->createTwitchMember('done@x.test', 'wkdone', 'Done', 'done-wk');
        $this->entityManager->persist(new WeeklyEntry(bin2hex(random_bytes(16)), $weekly->getId(), $member->getId(), 1, $this->now, $this->now));
        $this->entityManager->flush();

        // The member is live, but the run is over - it must not surface.
        $this->fakeLive(['wkdone' => 5]);

        $this->client->request('GET', '/api/v1/weekly-runs/'.$weekly->getId().'/participant-streams');

        $this->assertResponseStatusCodeSame(200);
        self::assertSame([], $this->dataList());
    }

    public function testEmptyDataWhenNoParticipantHasTwitch(): void
    {
        $event = $this->createEvent('LAN', $this->now, $this->now->modify('+1 day'));
        $member = $this->createMemberWithLinks('only-yt@x.test', 'NoTwitch', 'noTwitch-em', [
            ['label' => 'YouTube', 'url' => 'https://youtube.com/@x'],
        ]);
        $this->createRegistration($event->getId(), $member->getId());

        $this->fakeLive([]);

        $this->client->request('GET', '/api/v1/events/'.$event->getId().'/participant-streams');

        $this->assertResponseStatusCodeSame(200);
        self::assertSame([], $this->dataList());
    }

    public function testUnknownSessionsReturn404(): void
    {
        $this->fakeLive([]);

        $this->client->request('GET', '/api/v1/events/does-not-exist/participant-streams');
        $this->assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/api/v1/runs/does-not-exist/participant-streams');
        $this->assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/api/v1/weekly-runs/does-not-exist/participant-streams');
        $this->assertResponseStatusCodeSame(404);
    }

    /**
     * @param array<string, int> $live
     */
    private function fakeLive(array $live): void
    {
        $fake = new class($live) implements TwitchApiClientInterface {
            /** @param array<string, int> $live */
            public function __construct(private array $live)
            {
            }

            public function fetchViewerCount(): ?int
            {
                return null;
            }

            public function fetchLiveLogins(array $logins): array
            {
                return array_intersect_key($this->live, array_flip($logins));
            }

            public function fetchAvatars(array $logins): array
            {
                $avatars = [];
                foreach ($logins as $login) {
                    $avatars[$login] = 'https://cdn.test/'.$login.'.png';
                }

                return $avatars;
            }
        };

        self::getContainer()->set(TwitchApiClientInterface::class, $fake);
    }

    private function createTwitchMember(string $email, string $login, string $displayName, string $slug): User
    {
        return $this->createMemberWithLinks($email, $displayName, $slug, [
            ['label' => 'Twitch', 'url' => 'https://twitch.tv/'.$login],
        ]);
    }

    /**
     * @param list<array{label: string, url: string}> $links
     */
    private function createMemberWithLinks(string $email, string $displayName, string $slug, array $links): User
    {
        $user = $this->createUser($email, displayName: $displayName, slug: $slug);
        $profile = CommunityProfile::create($user->getId(), $this->now);
        $profile->customize(null, null, null, null, BannerPreset::DEFAULT, null, $links, [], Audience::MEMBERS, [], $this->now);
        $this->entityManager->persist($profile);
        $this->entityManager->flush();

        return $user;
    }

    /**
     * @return list<array{userId: string, slug: string, displayName: string|null, twitchLogin: string, avatarUrl: string|null, live: bool, viewerCount: int|null}>
     */
    private function dataList(): array
    {
        $body = $this->decodedJsonResponse();
        self::assertArrayHasKey('data', $body);
        self::assertIsArray($body['data']);

        /** @var list<array{userId: string, slug: string, displayName: string|null, twitchLogin: string, avatarUrl: string|null, live: bool, viewerCount: int|null}> $data */
        $data = $body['data'];

        return $data;
    }
}
