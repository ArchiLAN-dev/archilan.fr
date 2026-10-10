<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Community\Application\Query\ReportQueryFilters;
use App\Community\Application\Service\ModerationService;
use App\Community\Domain\Entity\Block;
use App\Community\Domain\Entity\ContentReport;
use App\Community\Domain\Entity\Friendship;
use App\Community\Domain\Entity\Notification;
use App\Identity\Domain\Entity\User;
use App\PersonalRuns\Application\Handler\ExpireRunListingsHandler;
use App\PersonalRuns\Application\Message\ExpireRunListingsMessage;
use App\PersonalRuns\Domain\Entity\Run;
use App\PersonalRuns\Domain\Entity\RunParticipant;

/**
 * Story 43.17: a draft run listed for every member, « Parties qui cherchent des joueurs ».
 */
final class RunListingsTest extends FunctionalTestCase
{
    private User $owner;

    private Run $run;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->createUser('owner@example.org', ['ROLE_USER'], 'Owner', 'owner');
        $game = $this->createGame('Hollow Knight', 'hollow-knight');
        $this->run = Run::create($this->owner->getId(), 'Ma run', new \DateTimeImmutable());
        $this->run->configureGames([['gameId' => $game->getId()]], new \DateTimeImmutable());
        $this->entityManager->persist($this->run);
        $this->entityManager->flush();
    }

    public function testAnyMemberFindsTheListingAndJoinsIt(): void
    {
        $stranger = $this->member('stranger');
        $friendOfStranger = $this->member('pal');
        $this->befriend($stranger, $friendOfStranger);
        $this->entityManager->persist(RunParticipant::create($this->run->getId(), $friendOfStranger->getId(), new \DateTimeImmutable()));
        $this->entityManager->flush();

        $this->list('On cherche deux joueurs pour un async tranquille.', 3, '2026-11-02T20:00:00+01:00');
        self::assertResponseStatusCodeSame(200);

        $listings = $this->listingsOf($stranger);
        self::assertCount(1, $listings);
        $listing = $listings[0];
        self::assertIsArray($listing);
        self::assertSame('On cherche deux joueurs pour un async tranquille.', $listing['pitch']);
        self::assertSame(['Hollow Knight'], $listing['games']);
        self::assertSame(1, $listing['joined']);
        self::assertNotNull($listing['plannedFor']);
        $friendsIn = $listing['friendsIn'];
        self::assertIsArray($friendsIn);
        self::assertSame(['pal'], array_column($friendsIn, 'slug'), 'the viewer\'s friends already in are put forward');

        $this->join();
        self::assertResponseStatusCodeSame(200);
        self::assertSame([], $this->listingsOf($stranger), 'joined: no longer offered');
        self::assertCount(1, $this->entityManager->getRepository(Notification::class)->findBy(['recipientId' => $this->owner->getId(), 'type' => 'run_joined']));
    }

    public function testAListingNeedsAShortMessageAndAnAccountInGoodStanding(): void
    {
        $this->list('   ', null);
        self::assertResponseStatusCodeSame(422);
        $this->list(str_repeat('a', 281), null);
        self::assertResponseStatusCodeSame(422);

        $this->list('Partie du jeudi', 1);
        self::assertResponseStatusCodeSame(200);
        $suspended = $this->member('suspended');
        $suspended->suspendUntil(new \DateTimeImmutable('+3 days'), 'spam', new \DateTimeImmutable());
        $this->entityManager->flush();
        // A suspended account is locked out of the API altogether; the commands refuse it as well.
        $this->loginAs($suspended);
        $this->join();
        self::assertResponseStatusCodeSame(401);

        $viewer = $this->member('viewer');
        self::assertCount(1, $this->listingsOf($viewer));
        $owner = $this->entityManager->getRepository(User::class)->find($this->owner->getId());
        self::assertInstanceOf(User::class, $owner);
        $owner->suspendUntil(new \DateTimeImmutable('+3 days'), 'spam', new \DateTimeImmutable());
        $this->entityManager->flush();
        self::assertSame([], $this->listingsOf($viewer), 'a suspended owner\'s listing is hidden');
        $this->list('Partie du vendredi', 1);
        self::assertResponseStatusCodeSame(401);
    }

    public function testABlockHidesTheListingBothWaysAndAFullRunLeavesTheList(): void
    {
        $blocker = $this->member('blocker');
        $blocked = $this->member('blocked');
        $first = $this->member('first');
        $second = $this->member('second');
        $this->entityManager->persist(Block::create($blocker->getId(), $this->owner->getId(), new \DateTimeImmutable()));
        $this->entityManager->persist(Block::create($this->owner->getId(), $blocked->getId(), new \DateTimeImmutable()));
        $this->entityManager->flush();
        $this->list('Une place', 1);

        foreach ([$blocker, $blocked] as $member) {
            self::assertSame([], $this->listingsOf($member));
            $this->join();
            self::assertResponseStatusCodeSame(404);
        }

        $this->loginAs($first);
        $this->join();
        self::assertResponseStatusCodeSame(200);
        self::assertSame([], $this->listingsOf($second));
        $this->join();
        self::assertResponseStatusCodeSame(409);
    }

    public function testTakingAListingDownAndBackUpKeepsItsAge(): void
    {
        $this->run->listForMembers('Annonce', null, null, new \DateTimeImmutable('-10 days'));
        $this->entityManager->flush();
        $listedAt = $this->run->getListedAt();

        $this->list('Annonce', null);
        $this->loginAs($this->owner);
        $this->client->request('PUT', '/api/v1/runs/'.$this->run->getId().'/openness', content: '{"openness":"invite"}');
        $this->list('Annonce remontée', null);
        self::assertResponseStatusCodeSame(200);

        $this->entityManager->clear();
        $run = $this->entityManager->getRepository(Run::class)->find($this->run->getId());
        self::assertInstanceOf(Run::class, $run);
        self::assertSame($listedAt?->format('Y-m-d H:i:s'), $run->getListedAt()?->format('Y-m-d H:i:s'), 'story 43.19: no fresh start by toggling');
    }

    public function testACancelledRunComesBackOnInvitation(): void
    {
        $this->list('Annonce', null);
        $this->loginAs($this->owner);
        $this->client->request('POST', '/api/v1/runs/'.$this->run->getId().'/archive');
        self::assertResponseIsSuccessful();
        $this->client->request('POST', '/api/v1/runs/'.$this->run->getId().'/unarchive');
        self::assertResponseIsSuccessful();

        $this->entityManager->clear();
        $run = $this->entityManager->getRepository(Run::class)->find($this->run->getId());
        self::assertInstanceOf(Run::class, $run);
        self::assertSame(Run::OPEN_INVITE, $run->getOpenness());
        self::assertSame([], $this->listingsOf($this->member('viewer')));
    }

    public function testAListingNobodyJoinedFor14DaysExpires(): void
    {
        $this->run->listForMembers('Vieille annonce', null, null, new \DateTimeImmutable('-15 days'));
        $alive = Run::create($this->owner->getId(), 'Run vivante', new \DateTimeImmutable());
        $alive->listForMembers('Annonce active', null, null, new \DateTimeImmutable('-15 days'));
        $alive->recordArrival(new \DateTimeImmutable('-2 days'));
        $this->entityManager->persist($alive);
        $this->entityManager->flush();

        $handler = self::getContainer()->get(ExpireRunListingsHandler::class);
        self::assertInstanceOf(ExpireRunListingsHandler::class, $handler);
        $handler(new ExpireRunListingsMessage());

        $this->entityManager->clear();
        $expired = $this->entityManager->getRepository(Run::class)->find($this->run->getId());
        self::assertInstanceOf(Run::class, $expired);
        self::assertSame(Run::OPEN_INVITE, $expired->getOpenness());
        self::assertNull($expired->getPitch());
        $kept = $this->entityManager->getRepository(Run::class)->find($alive->getId());
        self::assertInstanceOf(Run::class, $kept);
        self::assertSame(Run::OPEN_MEMBERS, $kept->getOpenness(), 'an arrival keeps it alive');

        $notices = $this->entityManager->getRepository(Notification::class)->findBy(['recipientId' => $this->owner->getId(), 'type' => 'run_listing_expired']);
        self::assertCount(1, $notices);
        self::assertSame('Ma run', $notices[0]->getPayload()['runTitle'] ?? null);
    }

    public function testAListingCanBeReportedToTheModerators(): void
    {
        $reporter = $this->member('reporter');
        $this->list('Venez jouer', null);

        $this->loginAs($reporter);
        $this->report('spam');
        self::assertResponseStatusCodeSame(204);
        $this->report('nonsense');
        self::assertResponseStatusCodeSame(422);
        $this->loginAs($this->owner);
        $this->report('spam');
        self::assertResponseStatusCodeSame(403);

        self::assertCount(1, $this->entityManager->getRepository(ContentReport::class)->findBy(['targetType' => ContentReport::TARGET_RUN_LISTING]));
        $moderation = self::getContainer()->get(ModerationService::class);
        self::assertInstanceOf(ModerationService::class, $moderation);
        $queue = $moderation->list(ReportQueryFilters::fromRaw(null, null, ContentReport::TARGET_RUN_LISTING, null, null, 20));
        self::assertCount(1, $queue['reports']);
        $listing = $queue['reports'][0]['runListing'] ?? null;
        self::assertNotNull($listing);
        self::assertSame('Venez jouer', $listing['pitch']);
        self::assertFalse($listing['changedSince']);

        // Story 43.18: the owner takes the listing down; the moderators still read what was reported.
        $this->loginAs($this->owner);
        $this->client->request('PUT', '/api/v1/runs/'.$this->run->getId().'/openness', content: '{"openness":"invite"}');
        self::assertResponseStatusCodeSame(200);
        $queue = $moderation->list(ReportQueryFilters::fromRaw(null, null, ContentReport::TARGET_RUN_LISTING, null, null, 20));
        $listing = $queue['reports'][0]['runListing'] ?? null;
        self::assertNotNull($listing);
        self::assertSame('Venez jouer', $listing['pitch']);
        self::assertTrue($listing['changedSince']);
    }

    public function testTheLastSeatGoesToOneMemberOnly(): void
    {
        $first = $this->member('first');
        $second = $this->member('second');
        $this->list('Une seule place', 1);

        $this->loginAs($first);
        $this->join();
        self::assertResponseStatusCodeSame(200);
        $this->loginAs($second);
        $this->join();
        self::assertResponseStatusCodeSame(409);
        self::assertSame(1, $this->entityManager->getRepository(RunParticipant::class)->count(['runId' => $this->run->getId()]));
    }

    // ─── helpers ────────────────────────────────────────────────────────────────

    private function member(string $slug): User
    {
        return $this->createUser($slug.'@example.org', ['ROLE_USER'], ucfirst($slug), $slug);
    }

    private function befriend(User $a, User $b): void
    {
        $friendship = Friendship::request($a->getId(), $b->getId(), new \DateTimeImmutable());
        $friendship->accept(new \DateTimeImmutable());
        $this->entityManager->persist($friendship);
        $this->entityManager->flush();
    }

    private function list(string $pitch, ?int $seats, ?string $plannedFor = null): void
    {
        $this->loginAs($this->owner);
        $this->client->request('PUT', '/api/v1/runs/'.$this->run->getId().'/openness', content: json_encode(
            ['openness' => Run::OPEN_MEMBERS, 'seatsWanted' => $seats, 'pitch' => $pitch, 'plannedFor' => $plannedFor],
            \JSON_THROW_ON_ERROR,
        ));
    }

    private function join(): void
    {
        $this->client->request('POST', '/api/v1/runs/'.$this->run->getId().'/join-open');
    }

    private function report(string $problem): void
    {
        $this->client->request('POST', '/api/v1/community/run-listings/'.$this->run->getId().'/report', content: json_encode(['problem' => $problem], \JSON_THROW_ON_ERROR));
    }

    /** @return list<mixed> */
    private function listingsOf(User $viewer): array
    {
        $this->loginAs($viewer);
        $this->client->request('GET', '/api/v1/run-listings');
        self::assertResponseStatusCodeSame(200);
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);

        return array_values($data);
    }
}
