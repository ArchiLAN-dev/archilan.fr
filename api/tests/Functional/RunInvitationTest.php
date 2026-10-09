<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Community\Domain\Entity\Block;
use App\Community\Domain\Entity\Friendship;
use App\Community\Domain\Entity\Notification;
use App\Identity\Domain\Entity\User;
use App\PersonalRuns\Domain\Entity\Run;
use App\PersonalRuns\Domain\Entity\RunInvitation;
use App\PersonalRuns\Domain\Entity\RunParticipant;

/**
 * Story 43.1: friends invited by name into a personal run, next to the invite link.
 */
final class RunInvitationTest extends FunctionalTestCase
{
    private User $owner;

    private Run $run;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->createUser('owner@example.org', ['ROLE_USER'], 'Owner', 'owner');
        $this->run = Run::create($this->owner->getId(), 'Ma run', new \DateTimeImmutable());
        $this->entityManager->persist($this->run);
        $this->entityManager->flush();
    }

    public function testTheOwnerInvitesFriendsOnlyOnceEach(): void
    {
        $friend = $this->friendOfOwner('friend');
        $stranger = $this->createUser('stranger@example.org', ['ROLE_USER'], 'Stranger', 'stranger');
        $already = $this->friendOfOwner('already');
        $this->entityManager->persist(RunParticipant::create($this->run->getId(), $already->getId(), new \DateTimeImmutable()));
        $this->entityManager->flush();

        $this->loginAs($this->owner);
        $this->invite([$friend->getId(), $stranger->getId(), $already->getId(), $this->owner->getId()]);
        self::assertResponseStatusCodeSame(200);
        $data = $this->decodedJsonResponse()['data'];
        self::assertIsArray($data);
        self::assertSame([$friend->getId()], $data['invited']);
        self::assertCount(3, self::list($data['skipped']), 'a stranger, a participant and the owner are left out');

        $this->invite([$friend->getId()]);
        self::assertSame([], $this->invitedNow(), 'still pending: not sent twice');

        $notices = $this->entityManager->getRepository(Notification::class)->findBy(['recipientId' => $friend->getId(), 'type' => 'run_invitation']);
        self::assertCount(1, $notices);
        self::assertSame('Ma run', $notices[0]->getPayload()['runTitle'] ?? null);
    }

    public function testTheFriendJoinsFromTheirInvitations(): void
    {
        $friend = $this->friendOfOwner('friend');
        $this->loginAs($this->owner);
        $this->invite([$friend->getId()]);

        $this->loginAs($friend);
        $this->client->request('GET', '/api/v1/account/run-invitations');
        self::assertResponseStatusCodeSame(200);
        $mine = self::list($this->decodedJsonResponse()['data']);
        self::assertCount(1, $mine);
        $invitation = $mine[0];
        self::assertIsArray($invitation);
        self::assertSame('Ma run', $invitation['runTitle']);
        $inviter = $invitation['inviter'];
        self::assertIsArray($inviter);
        self::assertSame('owner', $inviter['slug']);
        self::assertIsString($invitation['invitationId']);

        $this->client->request('POST', '/api/v1/run-invitations/'.$invitation['invitationId'].'/accept');
        self::assertResponseStatusCodeSame(200);
        $this->entityManager->clear();
        self::assertNotNull($this->entityManager->getRepository(RunParticipant::class)->findOneBy(['runId' => $this->run->getId(), 'userId' => $friend->getId()]));

        $this->client->request('GET', '/api/v1/account/run-invitations');
        self::assertSame([], $this->decodedJsonResponse()['data'], 'answered: no longer pending');

        $this->loginAs($this->owner);
        $this->client->request('GET', '/api/v1/runs/'.$this->run->getId().'/invitations');
        self::assertResponseStatusCodeSame(200);
        $rows = self::list($this->decodedJsonResponse()['data']);
        self::assertCount(1, $rows);
        self::assertIsArray($rows[0]);
        self::assertSame('accepted', $rows[0]['status']);
    }

    public function testADeclinedInvitationIsNotSentAgainTheSameDay(): void
    {
        $friend = $this->friendOfOwner('friend');
        $this->loginAs($this->owner);
        $this->invite([$friend->getId()]);

        $this->loginAs($friend);
        $this->client->request('POST', '/api/v1/run-invitations/'.$this->invitationOf($friend)->getId().'/decline');
        self::assertResponseStatusCodeSame(200);

        $this->loginAs($this->owner);
        $this->invite([$friend->getId()]);
        self::assertSame([], $this->invitedNow());
    }

    public function testABlockOrAnEndedRunClosesTheInvitation(): void
    {
        $blocked = $this->friendOfOwner('blocked');
        $late = $this->friendOfOwner('late');
        $this->loginAs($this->owner);
        $this->invite([$blocked->getId(), $late->getId()]);
        self::assertCount(2, $this->invitedNow());

        $this->entityManager->persist(Block::create($blocked->getId(), $this->owner->getId(), new \DateTimeImmutable()));
        $this->entityManager->flush();
        $this->loginAs($blocked);
        $this->client->request('POST', '/api/v1/run-invitations/'.$this->invitationOf($blocked)->getId().'/accept');
        self::assertResponseStatusCodeSame(409);
        self::assertSame(RunInvitation::CLOSED, $this->invitationOf($blocked)->getStatus());

        $run = $this->entityManager->find(Run::class, $this->run->getId());
        self::assertInstanceOf(Run::class, $run);
        $run->cancel(new \DateTimeImmutable());
        $this->entityManager->flush();
        $this->loginAs($late);
        $this->client->request('GET', '/api/v1/account/run-invitations');
        self::assertSame([], $this->decodedJsonResponse()['data'], 'an ended run is not shown');
        $this->client->request('POST', '/api/v1/run-invitations/'.$this->invitationOf($late)->getId().'/accept');
        self::assertResponseStatusCodeSame(409);

        $this->loginAs($this->owner);
        $this->invite([$late->getId()]);
        self::assertResponseStatusCodeSame(409, 'an ended run takes no one in');
    }

    public function testOnlyTheOwnerInvitesAndADayHasACap(): void
    {
        $friend = $this->friendOfOwner('friend');
        $this->loginAs($friend);
        $this->invite([$friend->getId()]);
        self::assertResponseStatusCodeSame(403);

        $many = [];
        foreach (range(1, 21) as $n) {
            $many[] = $this->friendOfOwner('f'.$n)->getId();
        }
        $this->loginAs($this->owner);
        $this->invite($many);
        self::assertResponseStatusCodeSame(429);
        $this->invite(array_slice($many, 0, 20));
        self::assertResponseStatusCodeSame(200);
    }

    // ─── helpers ────────────────────────────────────────────────────────────────

    private function friendOfOwner(string $slug): User
    {
        $user = $this->createUser($slug.'@example.org', ['ROLE_USER'], ucfirst($slug), $slug);
        $friendship = Friendship::request($this->owner->getId(), $user->getId(), new \DateTimeImmutable());
        $friendship->accept(new \DateTimeImmutable());
        $this->entityManager->persist($friendship);
        $this->entityManager->flush();

        return $user;
    }

    /** @param list<string> $userIds */
    private function invite(array $userIds): void
    {
        $this->client->request('POST', '/api/v1/runs/'.$this->run->getId().'/invitations', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['userIds' => $userIds], \JSON_THROW_ON_ERROR));
    }

    /** @return list<mixed> */
    private function invitedNow(): array
    {
        $data = $this->decodedJsonResponse()['data'];
        self::assertIsArray($data);

        return self::list($data['invited']);
    }

    private function invitationOf(User $invitee): RunInvitation
    {
        $this->entityManager->clear();
        $invitation = $this->entityManager->getRepository(RunInvitation::class)->findOneBy(['runId' => $this->run->getId(), 'inviteeId' => $invitee->getId()]);
        self::assertInstanceOf(RunInvitation::class, $invitation);

        return $invitation;
    }

    /** @return list<mixed> */
    private static function list(mixed $value): array
    {
        self::assertIsArray($value);

        return array_values($value);
    }
}
