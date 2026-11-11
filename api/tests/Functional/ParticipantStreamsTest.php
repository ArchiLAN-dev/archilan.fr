<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Community\Domain\Entity\CommunityProfile;
use App\Community\Domain\ValueObject\Audience;
use App\Community\Domain\ValueObject\BannerPreset;
use App\Events\Domain\Entity\Event;
use App\Identity\Domain\Entity\User;
use App\PersonalRuns\Domain\Entity\Run;
use App\PersonalRuns\Domain\Entity\RunParticipant;
use App\Registrations\Domain\Entity\Registration;
use App\Streaming\Application\Port\TwitchApiClientInterface;
use App\Streaming\Application\Support\MediaPublishCredentials;
use App\Streaming\Domain\Entity\LocalStream;
use App\WeeklyRuns\Domain\Entity\WeeklyEntry;
use App\WeeklyRuns\Domain\Entity\WeeklyRun;

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
        $event = $this->createEvent('LAN', $this->now, $this->now->modify('+1 day'), published: true);

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

        self::assertResponseStatusCodeSame(200);
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

    public function testTheBroadcastListsOnlyTheLiveMembersWhoAgreed(): void
    {
        $event = $this->createEvent('LAN', $this->now, $this->now->modify('+1 day'), published: true);
        $agreed = $this->createTwitchMember('agreed@x.test', 'bcagreed', 'Alice', 'alice-bc');
        $refused = $this->createTwitchMember('refused@x.test', 'bcrefused', 'Bob', 'bob-bc');
        $offline = $this->createTwitchMember('offline@x.test', 'bcoffline', 'Carol', 'carol-bc');

        $this->createRegistration($event->getId(), $agreed->getId());
        $this->createRegistration($event->getId(), $refused->getId())->chooseBroadcastConsent(false, $this->now);
        $this->createRegistration($event->getId(), $offline->getId());
        $this->entityManager->flush();
        $this->fakeLive(['bcagreed' => 12, 'bcrefused' => 30]);

        $this->client->request('GET', '/api/v1/events/'.$event->getId().'/broadcast-streams');
        self::assertResponseStatusCodeSame(200);
        $data = $this->dataList();
        self::assertCount(1, $data);
        self::assertSame('bcagreed', $data[0]['twitchLogin']);

        $this->client->request('GET', '/api/v1/events/unknown/broadcast-streams');
        self::assertResponseStatusCodeSame(404);
    }

    public function testTheBroadcastPutsTheLanStreamsFirstAndOnlyOnce(): void
    {
        $event = $this->createEvent('LAN', $this->now, $this->now->modify('+1 day'), published: true);
        $event->pointMediaServer('media.lan.archilan.fr', $this->now);
        $both = $this->createTwitchMember('both@x.test', 'lanboth', 'Alice', 'alice-lan');
        $twitchOnly = $this->createTwitchMember('twitch@x.test', 'lantwitch', 'Bob', 'bob-lan');
        $noTwitch = $this->createUser('local@x.test', displayName: 'Carol', slug: 'carol-lan');
        $refused = $this->createUser('refused@x.test', displayName: 'Dan', slug: 'dan-lan');
        $cut = $this->createUser('cut@x.test', displayName: 'Eve', slug: 'eve-lan');

        $bothRegistration = $this->createRegistration($event->getId(), $both->getId());
        $this->createRegistration($event->getId(), $twitchOnly->getId());
        $localRegistration = $this->createRegistration($event->getId(), $noTwitch->getId());
        $refusedRegistration = $this->createRegistration($event->getId(), $refused->getId());
        $refusedRegistration->chooseBroadcastConsent(false, $this->now);
        $cutRegistration = $this->createRegistration($event->getId(), $cut->getId());

        // What the régie agent last reported (story 47.6): Eve's stream was cut.
        $now = new \DateTimeImmutable();
        $credentials = self::getContainer()->get(MediaPublishCredentials::class);
        self::assertInstanceOf(MediaPublishCredentials::class, $credentials);
        foreach ([$bothRegistration, $localRegistration, $refusedRegistration, $cutRegistration] as $registration) {
            $stream = new LocalStream(bin2hex(random_bytes(16)), $event->getId(), $registration->getId(), $credentials->pathFor($registration->getId()), $now);
            $stream->seenOnline(null, $now);
            if ($registration === $cutRegistration) {
                $stream->seenOffline();
            }
            $this->entityManager->persist($stream);
        }
        $this->entityManager->flush();
        $this->fakeLive(['lanboth' => 5, 'lantwitch' => 7]);

        $this->client->request('GET', '/api/v1/events/'.$event->getId().'/broadcast-streams');
        self::assertResponseStatusCodeSame(200);
        $data = $this->dataList();

        self::assertSame(['local', 'local', 'twitch'], array_column($data, 'kind'));
        self::assertSame(['alice-lan', 'carol-lan', 'bob-lan'], array_column($data, 'slug'));
        self::assertSame(
            sprintf('https://media.lan.archilan.fr:8889/%s/whep', $credentials->pathFor($localRegistration->getId())),
            $data[1]['whepUrl'] ?? null,
        );

        // The public lists never carry a LAN stream.
        $this->client->request('GET', '/api/v1/events/'.$event->getId().'/participant-streams');
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['lanboth', 'lantwitch'], array_column($this->dataList(), 'twitchLogin'));
    }

    public function testBannedAndSuspendedParticipantsAreExcluded(): void
    {
        $event = $this->createEvent('LAN', $this->now, $this->now->modify('+1 day'), published: true);

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

        self::assertResponseStatusCodeSame(200);
        $data = $this->dataList();

        self::assertCount(1, $data);
        self::assertSame('bnok', $data[0]['twitchLogin']);
    }

    public function testExpiredSuspensionIsIncluded(): void
    {
        $event = $this->createEvent('LAN', $this->now, $this->now->modify('+1 day'), published: true);

        $member = $this->createTwitchMember('expired@x.test', 'exok', 'Expired', 'exok-bn');
        // Suspension already over relative to the read layer's real-clock check - must be included.
        $member->suspendUntil(new \DateTimeImmutable('2020-01-01T00:00:00+00:00'), 'old', $this->now);
        $this->entityManager->flush();

        $this->createRegistration($event->getId(), $member->getId());

        $this->fakeLive([]);

        $this->client->request('GET', '/api/v1/events/'.$event->getId().'/participant-streams');

        self::assertResponseStatusCodeSame(200);
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

        $this->loginAs($member);
        $this->client->request('GET', '/api/v1/runs/'.$run->getId().'/participant-streams');

        self::assertResponseStatusCodeSame(200);
        $data = $this->dataList();

        self::assertCount(1, $data);
        self::assertSame('runlive', $data[0]['twitchLogin']);
        self::assertTrue($data[0]['live']);
        self::assertSame(7, $data[0]['viewerCount']);
    }

    public function testAPrivateEventKeepsItsStreamsPublic(): void
    {
        $event = $this->createEvent('LAN', $this->now, $this->now->modify('+1 day'), published: true, isPublic: false);
        $member = $this->createTwitchMember('private@x.test', 'pvlive', 'Private', 'private-pv');
        $this->createRegistration($event->getId(), $member->getId());

        $this->fakeLive([]);

        $this->client->request('GET', '/api/v1/events/'.$event->getId().'/participant-streams');

        self::assertResponseStatusCodeSame(200);
        self::assertCount(1, $this->dataList());
    }

    public function testADraftShowsItsStreamsToTheAdminsOnly(): void
    {
        $event = $this->createEvent('LAN', $this->now, $this->now->modify('+1 day'));
        $member = $this->createTwitchMember('draft@x.test', 'drlive', 'Draft', 'draft-dr');
        $this->createRegistration($event->getId(), $member->getId());
        $admin = $this->createUser('admin@x.test', ['ROLE_ADMIN'], 'Admin', 'admin-dr');
        $path = '/api/v1/events/'.$event->getId().'/participant-streams';

        $this->fakeLive([]);

        $this->client->request('GET', $path);
        self::assertResponseStatusCodeSame(404);

        $this->loginAs($member);
        $this->client->request('GET', $path);
        self::assertResponseStatusCodeSame(404);

        $this->loginAs($admin);
        $this->client->request('GET', $path);
        self::assertResponseStatusCodeSame(200);
    }

    public function testAPersonalRunShowsItsStreamsToItsAudienceOnly(): void
    {
        $owner = $this->createUser('owner@x.test', displayName: 'Owner', slug: 'owner-rn');
        $stranger = $this->createUser('stranger@x.test', displayName: 'Stranger', slug: 'stranger-rn');
        $admin = $this->createUser('admin@x.test', ['ROLE_ADMIN'], 'Admin', 'admin-rn');
        $run = Run::create($owner->getId(), 'My run', $this->now);
        $this->entityManager->persist($run);
        $this->entityManager->flush();
        $path = '/api/v1/runs/'.$run->getId().'/participant-streams';

        $this->fakeLive([]);

        $this->client->request('GET', $path);
        self::assertResponseStatusCodeSame(404);

        $this->loginAs($stranger);
        $this->client->request('GET', $path);
        self::assertResponseStatusCodeSame(404);

        $this->loginAs($owner);
        $this->client->request('GET', $path);
        self::assertResponseStatusCodeSame(200);

        $this->loginAs($admin);
        $this->client->request('GET', $path);
        self::assertResponseStatusCodeSame(200);
    }

    public function testAPublishedRecapOpensTheStreamsOfTheRun(): void
    {
        $run = Run::create('owner-id', 'My run', $this->now);
        $run->publishRecap($this->now);
        $this->entityManager->persist($run);
        $this->entityManager->flush();

        $this->fakeLive([]);

        $this->client->request('GET', '/api/v1/runs/'.$run->getId().'/participant-streams');

        self::assertResponseStatusCodeSame(200);
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

        self::assertResponseStatusCodeSame(200);
        $data = $this->dataList();

        self::assertCount(1, $data);
        self::assertSame('wklive', $data[0]['twitchLogin']);
    }

    public function testCompletedEventReturnsNoStreams(): void
    {
        $event = $this->createEvent('LAN', $this->now, $this->now->modify('+1 day'), published: true);
        $this->transitionEventTo($event, Event::STATUS_COMPLETED, $this->now);
        $this->entityManager->flush();

        $member = $this->createTwitchMember('ev-done@x.test', 'evdone', 'EvDone', 'evdone-ev');
        $this->createRegistration($event->getId(), $member->getId());

        // The member is live, but the event is over - it must not surface.
        $this->fakeLive(['evdone' => 9]);

        $this->client->request('GET', '/api/v1/events/'.$event->getId().'/participant-streams');

        self::assertResponseStatusCodeSame(200);
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

        self::assertResponseStatusCodeSame(200);
        self::assertSame([], $this->dataList());
    }

    public function testEmptyDataWhenNoParticipantHasTwitch(): void
    {
        $event = $this->createEvent('LAN', $this->now, $this->now->modify('+1 day'), published: true);
        $member = $this->createMemberWithLinks('only-yt@x.test', 'NoTwitch', 'noTwitch-em', [
            ['label' => 'YouTube', 'url' => 'https://youtube.com/@x'],
        ]);
        $this->createRegistration($event->getId(), $member->getId());

        $this->fakeLive([]);

        $this->client->request('GET', '/api/v1/events/'.$event->getId().'/participant-streams');

        self::assertResponseStatusCodeSame(200);
        self::assertSame([], $this->dataList());
    }

    public function testUnknownSessionsReturn404(): void
    {
        $this->fakeLive([]);

        $this->client->request('GET', '/api/v1/events/does-not-exist/participant-streams');
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/api/v1/runs/does-not-exist/participant-streams');
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/api/v1/weekly-runs/does-not-exist/participant-streams');
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @param array<string, int> $live
     */
    private function fakeLive(array $live): void
    {
        $fake = new readonly class($live) implements TwitchApiClientInterface {
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
     * @return list<array<string, mixed>>
     */
    private function dataList(): array
    {
        $body = $this->decodedJsonResponse();
        self::assertArrayHasKey('data', $body);
        self::assertIsArray($body['data']);

        /** @var list<array<string, mixed>> $data */
        $data = $body['data'];

        return $data;
    }
}
