<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Community\Domain\Entity\Block;
use App\Community\Domain\Entity\CommunityProfile;
use App\Community\Domain\Entity\Friendship;
use App\Community\Domain\Enum\PresenceVisibility;
use App\Identity\Domain\Entity\User;
use App\Membership\Domain\Entity\Membership;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Entity\SessionSlot;

/**
 * Story 43.6: « Qui voit quand je joue », applied by the presence read for every viewer tier.
 */
final class PresenceVisibilityTest extends FunctionalTestCase
{
    private User $alice;

    protected function setUp(): void
    {
        parent::setUp();
        $now = new \DateTimeImmutable('-5 minutes');
        $this->alice = $this->createUser('alice@example.org', displayName: 'Alice', slug: 'alice');
        $event = $this->createEvent('LAN', $now, $now->modify('+1 day'), 20, published: true, isPublic: true);
        $game = $this->createGame('Hollow Knight', 'hollow-knight');
        $registration = $this->createRegistration($event->getId(), $this->alice->getId());

        $session = Session::create(bin2hex(random_bytes(16)), $event->getId(), $now);
        foreach ([Session::STATUS_VALIDATING, Session::STATUS_READY, Session::STATUS_GENERATING, Session::STATUS_GENERATED, Session::STATUS_LAUNCHING] as $status) {
            $session->transition($status, $now);
        }
        $session->transition(Session::STATUS_RUNNING, $now, 'bridge.local', 38281, 'secret', 5000);
        $this->entityManager->persist($session);
        $this->entityManager->persist(SessionSlot::create(bin2hex(random_bytes(16)), $session->getId(), $registration->getId(), $game->getId(), 'Alice', 0));
        $this->entityManager->flush();
    }

    public function testEachSettingShowsThePresenceToItsViewersOnly(): void
    {
        $member = $this->createUser('member@example.org', slug: 'member');
        $this->entityManager->persist(Membership::create($member->getId(), new \DateTimeImmutable('-1 month'), new \DateTimeImmutable('+11 months'), 'admin', null, null, new \DateTimeImmutable('-1 month')));
        $friend = $this->createUser('friend@example.org', slug: 'friend');
        $friendship = Friendship::request($friend->getId(), $this->alice->getId(), new \DateTimeImmutable());
        $friendship->accept(new \DateTimeImmutable());
        $this->entityManager->persist($friendship);
        $stranger = $this->createUser('stranger@example.org', slug: 'stranger');
        $this->entityManager->flush();

        $viewers = ['anonymous' => null, 'authenticated' => $stranger, 'member' => $member, 'friend' => $friend, 'self' => $this->alice];
        $expected = [
            'everyone' => ['anonymous' => true, 'authenticated' => true, 'member' => true, 'friend' => true, 'self' => true],
            'members' => ['anonymous' => false, 'authenticated' => false, 'member' => true, 'friend' => true, 'self' => true],
            'friends' => ['anonymous' => false, 'authenticated' => false, 'member' => false, 'friend' => true, 'self' => true],
            'nobody' => ['anonymous' => false, 'authenticated' => false, 'member' => false, 'friend' => false, 'self' => true],
        ];

        foreach ($expected as $visibility => $byViewer) {
            $this->setVisibility(PresenceVisibility::from($visibility));
            foreach ($viewers as $tier => $viewer) {
                self::assertSame($byViewer[$tier], $this->profilePlaying($viewer), sprintf('%s seen by %s', $visibility, $tier));
            }
        }
    }

    public function testAProfileWithoutARowShowsItsPresenceToEveryone(): void
    {
        self::assertTrue($this->profilePlaying(null));
    }

    public function testABlockHidesThePresenceWhateverTheSetting(): void
    {
        $blocked = $this->createUser('blocked@example.org', slug: 'blocked');
        $this->entityManager->persist(Block::create($this->alice->getId(), $blocked->getId(), new \DateTimeImmutable()));
        $this->entityManager->flush();

        self::assertFalse($this->profilePlaying($blocked));
        $this->loginAs($blocked);
        $this->client->request('GET', '/api/v1/community/overview');
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->hubSlugs());
    }

    public function testTheHubLeavesOutAHiddenPresence(): void
    {
        $this->client->request('GET', '/api/v1/community/overview');
        self::assertResponseIsSuccessful();
        self::assertSame(['alice'], $this->hubSlugs());

        $this->setVisibility(PresenceVisibility::Nobody);
        $this->client->request('GET', '/api/v1/community/overview');
        self::assertSame([], $this->hubSlugs());
    }

    public function testTheOwnerSavesTheSettingFromTheProfileForm(): void
    {
        $this->loginAs($this->alice);
        $this->client->request('GET', '/api/v1/community/profile');
        self::assertResponseIsSuccessful();
        self::assertSame('everyone', $this->data()['presenceVisibility']);

        $this->client->jsonRequest('PUT', '/api/v1/community/profile', ['presenceVisibility' => 'friends']);
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/api/v1/community/profile');
        self::assertSame('friends', $this->data()['presenceVisibility']);

        $this->client->jsonRequest('PUT', '/api/v1/community/profile', ['bio' => 'Salut']);
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/api/v1/community/profile');
        self::assertSame('friends', $this->data()['presenceVisibility'], 'an omitted setting is kept');

        $this->client->jsonRequest('PUT', '/api/v1/community/profile', ['presenceVisibility' => 'secret']);
        self::assertResponseStatusCodeSame(422);
    }

    // ─── helpers ────────────────────────────────────────────────────────────────

    private function setVisibility(PresenceVisibility $visibility): void
    {
        $this->entityManager->clear();
        $repository = $this->entityManager->getRepository(CommunityProfile::class);
        $profile = $repository->findOneBy(['userId' => $this->alice->getId()]);
        if (!$profile instanceof CommunityProfile) {
            $profile = CommunityProfile::create($this->alice->getId(), new \DateTimeImmutable());
            $this->entityManager->persist($profile);
        }
        $profile->choosePresenceVisibility($visibility, new \DateTimeImmutable());
        $this->entityManager->flush();
    }

    private function profilePlaying(?User $viewer): bool
    {
        $this->client->restart();
        if (null !== $viewer) {
            $this->loginAs($viewer);
        }
        $this->client->request('GET', '/api/v1/community/profiles/alice');
        self::assertResponseIsSuccessful();
        $presence = $this->data()['presence'] ?? null;
        self::assertIsArray($presence);
        self::assertIsBool($presence['playing']);

        return $presence['playing'];
    }

    /** @return list<string> */
    private function hubSlugs(): array
    {
        $playing = $this->data()['playingNow'] ?? null;
        self::assertIsArray($playing);
        $slugs = [];
        foreach ($playing as $row) {
            self::assertIsArray($row);
            self::assertIsString($row['slug']);
            $slugs[] = $row['slug'];
        }

        return $slugs;
    }

    /** @return array<mixed> */
    private function data(): array
    {
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);

        return $data;
    }
}
