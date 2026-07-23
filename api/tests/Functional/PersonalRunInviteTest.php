<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Membership\Domain\Entity\Membership;
use App\PersonalRuns\Domain\Entity\Run;
use App\PersonalRuns\Domain\Entity\RunParticipant;

final class PersonalRunInviteTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    // -------------------------------------------------------------------------
    // Regenerate invite token
    // -------------------------------------------------------------------------

    public function testRegenerateTokenAsOwnerReturns200WithNewToken(): void
    {
        $alice = $this->createUser('alice@example.org');
        $run = $this->createRun($alice->getId(), 'Alice Run');
        $oldToken = $run->getInviteToken();

        $this->loginAs($alice);
        $this->client->jsonRequest('POST', '/api/v1/runs/'.$run->getId().'/invite/regenerate');

        self::assertResponseIsSuccessful();
        $data = $this->responseData();
        self::assertIsString($data['inviteToken']);
        self::assertSame(64, strlen($data['inviteToken']));
        self::assertNotSame($oldToken, $data['inviteToken']);
        self::assertIsString($data['inviteUrl']);
        self::assertStringContainsString('/runs/join/'.$data['inviteToken'], $data['inviteUrl']);
    }

    public function testRegenerateTokenAsNonOwnerReturns403(): void
    {
        $alice = $this->createUser('alice@example.org');
        $bob = $this->createUser('bob@example.org');
        $run = $this->createRun($alice->getId(), 'Alice Run');

        $this->loginAs($bob);
        $this->client->jsonRequest('POST', '/api/v1/runs/'.$run->getId().'/invite/regenerate');
        self::assertResponseStatusCodeSame(403);
    }

    public function testRegenerateTokenUnauthenticatedReturns401(): void
    {
        $alice = $this->createUser('alice@example.org');
        $run = $this->createRun($alice->getId(), 'Alice Run');

        $this->client->jsonRequest('POST', '/api/v1/runs/'.$run->getId().'/invite/regenerate');
        self::assertResponseStatusCodeSame(401);
    }

    public function testRegenerateTokenOnFinishedRunReturns409AndKeepsToken(): void
    {
        // A finished (or cancelled) run is read-only: its invite link can no longer be regenerated (#338).
        $alice = $this->createUser('alice@example.org');
        $run = $this->createRun($alice->getId(), 'Alice Run', Run::STATUS_COMPLETED);
        $oldToken = $run->getInviteToken();

        $this->loginAs($alice);
        $this->client->jsonRequest('POST', '/api/v1/runs/'.$run->getId().'/invite/regenerate');
        self::assertResponseStatusCodeSame(409);
        self::assertSame('run_finished', $this->errorCode());

        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(Run::class, $run->getId());
        self::assertInstanceOf(Run::class, $reloaded);
        self::assertSame($oldToken, $reloaded->getInviteToken());
    }

    public function testRegenerateTokenPreservesExistingParticipants(): void
    {
        $alice = $this->createUser('alice@example.org');
        $bob = $this->createUser('bob@example.org');
        $run = $this->createRun($alice->getId(), 'Alice Run');

        // Bob joins
        $this->loginAs($bob);
        $this->client->jsonRequest('GET', '/api/v1/runs/join/'.$run->getInviteToken());
        self::assertResponseIsSuccessful();

        // Alice regenerates token
        $this->loginAs($alice);
        $this->client->jsonRequest('POST', '/api/v1/runs/'.$run->getId().'/invite/regenerate');
        self::assertResponseIsSuccessful();

        // Bob is still a participant
        $this->entityManager->clear();
        $participant = $this->entityManager->find(RunParticipant::class, [
            'runId' => $run->getId(),
            'userId' => $bob->getId(),
        ]);
        self::assertInstanceOf(RunParticipant::class, $participant);
    }

    // -------------------------------------------------------------------------
    // Join by invite token
    // -------------------------------------------------------------------------

    public function testJoinAsNewParticipantReturns200WithPayload(): void
    {
        $alice = $this->createUser('alice@example.org');
        $bob = $this->createUser('bob@example.org');
        $run = $this->createRun($alice->getId(), 'Alice Run');

        $this->loginAs($bob);
        $this->client->jsonRequest('GET', '/api/v1/runs/join/'.$run->getInviteToken());

        self::assertResponseIsSuccessful();
        $data = $this->responseData();
        self::assertSame($run->getId(), $data['id']);
        self::assertFalse($data['isOwner']);
        $participants = $this->participantsFromData($data);
        self::assertCount(1, $participants);
        self::assertSame($bob->getId(), $participants[0]['userId']);
    }

    public function testJoinIsIdempotent(): void
    {
        $alice = $this->createUser('alice@example.org');
        $bob = $this->createUser('bob@example.org');
        $run = $this->createRun($alice->getId(), 'Alice Run');

        $this->loginAs($bob);
        $this->client->jsonRequest('GET', '/api/v1/runs/join/'.$run->getInviteToken());
        self::assertResponseIsSuccessful();

        $this->client->jsonRequest('GET', '/api/v1/runs/join/'.$run->getInviteToken());
        self::assertResponseIsSuccessful();

        // Only one participant record
        $count = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(p.userId)')
            ->from(RunParticipant::class, 'p')
            ->where('p.runId = :runId')
            ->setParameter('runId', $run->getId())
            ->getQuery()
            ->getSingleScalarResult();
        self::assertSame(1, $count);
    }

    public function testOwnerFollowsOwnLinkReturns200WithNoParticipantCreated(): void
    {
        $alice = $this->createUser('alice@example.org');
        $run = $this->createRun($alice->getId(), 'Alice Run');

        $this->loginAs($alice);
        $this->client->jsonRequest('GET', '/api/v1/runs/join/'.$run->getInviteToken());

        self::assertResponseIsSuccessful();
        $data = $this->responseData();
        self::assertTrue($data['isOwner']);

        // No participant record created for owner
        $participant = $this->entityManager->find(RunParticipant::class, [
            'runId' => $run->getId(),
            'userId' => $alice->getId(),
        ]);
        self::assertNull($participant);
    }

    public function testJoinUnauthenticatedReturns401(): void
    {
        $alice = $this->createUser('alice@example.org');
        $run = $this->createRun($alice->getId(), 'Alice Run');

        $this->client->jsonRequest('GET', '/api/v1/runs/join/'.$run->getInviteToken());
        self::assertResponseStatusCodeSame(401);
        self::assertSame('auth_required', $this->errorCode());
    }

    public function testJoinWithInvalidTokenReturns404(): void
    {
        $alice = $this->createUser('alice@example.org');
        $this->loginAs($alice);

        $this->client->jsonRequest('GET', '/api/v1/runs/join/'.str_repeat('a', 64));
        self::assertResponseStatusCodeSame(404);
    }

    public function testJoinCancelledRunReturns404(): void
    {
        $alice = $this->createUser('alice@example.org');
        $bob = $this->createUser('bob@example.org');
        $run = $this->createRun($alice->getId(), 'Alice Run', Run::STATUS_CANCELLED);

        $this->loginAs($bob);
        $this->client->jsonRequest('GET', '/api/v1/runs/join/'.$run->getInviteToken());
        self::assertResponseStatusCodeSame(404);
    }

    // -------------------------------------------------------------------------
    // Preview endpoint (public, no auth)
    // -------------------------------------------------------------------------

    public function testPreviewReturns200WithoutAuth(): void
    {
        $alice = $this->createUser('alice@example.org', ['ROLE_USER'], 'Alice Dupont');
        $run = $this->createRun($alice->getId(), 'Alice Run');

        $this->client->jsonRequest('GET', '/api/v1/runs/invite/'.$run->getInviteToken().'/preview');

        self::assertResponseIsSuccessful();
        $data = $this->responseData();
        self::assertSame('Alice Run', $data['title']);
        self::assertSame('Alice Dupont', $data['ownerName']);
        self::assertSame(0, $data['participantCount']);
        self::assertSame(Run::STATUS_DRAFT, $data['status']);
    }

    public function testPreviewReflectsParticipantCount(): void
    {
        $alice = $this->createUser('alice@example.org');
        $bob = $this->createUser('bob@example.org');
        $run = $this->createRun($alice->getId(), 'Alice Run');

        $this->loginAs($bob);
        $this->client->jsonRequest('GET', '/api/v1/runs/join/'.$run->getInviteToken());

        $this->client->jsonRequest('GET', '/api/v1/runs/invite/'.$run->getInviteToken().'/preview');
        self::assertResponseIsSuccessful();
        $data = $this->responseData();
        self::assertSame(1, $data['participantCount']);
    }

    public function testPreviewCancelledRunReturns404(): void
    {
        $alice = $this->createUser('alice@example.org');
        $run = $this->createRun($alice->getId(), 'Alice Run', Run::STATUS_CANCELLED);

        $this->client->jsonRequest('GET', '/api/v1/runs/invite/'.$run->getInviteToken().'/preview');
        self::assertResponseStatusCodeSame(404);
    }

    // -------------------------------------------------------------------------
    // Get run - isOwner + participants in payload
    // -------------------------------------------------------------------------

    public function testGetRunPayloadIncludesIsOwnerTrueForOwner(): void
    {
        $alice = $this->createUser('alice@example.org');
        $run = $this->createRun($alice->getId(), 'Alice Run');

        $this->loginAs($alice);
        $this->client->jsonRequest('GET', '/api/v1/runs/'.$run->getId());

        self::assertResponseIsSuccessful();
        $data = $this->responseData();
        self::assertTrue($data['isOwner']);
        self::assertSame([], $data['participants']);
    }

    public function testGetRunPayloadIncludesIsOwnerFalseForParticipant(): void
    {
        $alice = $this->createUser('alice@example.org');
        $bob = $this->createUser('bob@example.org');
        $run = $this->createRun($alice->getId(), 'Alice Run');

        // Bob joins via invite
        $this->loginAs($bob);
        $this->client->jsonRequest('GET', '/api/v1/runs/join/'.$run->getInviteToken());

        $this->client->jsonRequest('GET', '/api/v1/runs/'.$run->getId());
        self::assertResponseIsSuccessful();
        $data = $this->responseData();
        self::assertFalse($data['isOwner']);
        $participants = $this->participantsFromData($data);
        self::assertCount(1, $participants);
        self::assertSame($bob->getId(), $participants[0]['userId']);
    }

    public function testGetRunPayloadExposesParticipantSlug(): void
    {
        $alice = $this->createUser('alice@example.org');
        $bob = $this->createUser('bob@example.org', slug: 'bob-slug');
        $run = $this->createRun($alice->getId(), 'Alice Run');

        $this->loginAs($bob);
        $this->client->jsonRequest('GET', '/api/v1/runs/join/'.$run->getInviteToken());

        $this->loginAs($alice);
        $this->client->jsonRequest('GET', '/api/v1/runs/'.$run->getId());
        self::assertResponseIsSuccessful();

        $participants = $this->participantsFromData($this->responseData());
        self::assertCount(1, $participants);
        self::assertSame($bob->getId(), $participants[0]['userId']);
        // The slug lets the frontend link a participant to their public player profile; the avatar URL
        // (null here - no community profile) and community display name come from the community card.
        self::assertSame('bob-slug', $participants[0]['slug']);
        self::assertArrayHasKey('avatarUrl', $participants[0]);
        self::assertNull($participants[0]['avatarUrl']);
    }

    public function testGetRunPayloadExposesParticipantBadges(): void
    {
        // Story 30.37 / issue #261: participants carry status badges (Adhérent / Admin / niveau / En jeu).
        $alice = $this->createUser('alice@example.org');
        $admin = $this->createUser('admin261@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $member = $this->createUser('member261@example.org');
        $this->createActiveMembership($member->getId());
        $run = $this->createRun($alice->getId(), 'Alice Run');

        $this->loginAs($admin);
        $this->client->jsonRequest('GET', '/api/v1/runs/join/'.$run->getInviteToken());
        $this->loginAs($member);
        $this->client->jsonRequest('GET', '/api/v1/runs/join/'.$run->getInviteToken());

        $this->loginAs($alice);
        $this->client->jsonRequest('GET', '/api/v1/runs/'.$run->getId());
        self::assertResponseIsSuccessful();

        $byId = [];
        foreach ($this->participantsFromData($this->responseData()) as $p) {
            self::assertIsString($p['userId']);
            $byId[$p['userId']] = $p;
        }

        $adminRow = $byId[$admin->getId()];
        self::assertTrue($adminRow['isAdmin']);
        self::assertFalse($adminRow['isMember']);
        self::assertFalse($adminRow['playing']);
        self::assertSame(0, $adminRow['level']);

        $memberRow = $byId[$member->getId()];
        self::assertTrue($memberRow['isMember']);
        self::assertFalse($memberRow['isAdmin']);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function createActiveMembership(string $userId): void
    {
        $now = new \DateTimeImmutable('2026-05-01T10:00:00+00:00');
        $membership = Membership::create($userId, $now, $now->modify('+1 year'), 'admin', null, null, $now);
        $this->entityManager->persist($membership);
        $this->entityManager->flush();
    }

    private function createRun(string $ownerId, string $title, string $status = Run::STATUS_DRAFT): Run
    {
        $now = new \DateTimeImmutable('2026-05-12T10:00:00+00:00');
        $run = Run::create($ownerId, $title, $now);

        if (Run::STATUS_DRAFT !== $status) {
            $reflection = new \ReflectionProperty(Run::class, 'status');
            $reflection->setValue($run, $status);
        }

        $this->entityManager->persist($run);
        $this->entityManager->flush();

        return $run;
    }

    /**
     * @return array<string, mixed>
     */
    private function responseData(): array
    {
        $decoded = $this->decodedResponse();
        $data = $decoded['data'] ?? null;
        self::assertIsArray($data);

        $result = [];
        foreach ($data as $key => $value) {
            self::assertIsString($key);
            $result[$key] = $value;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<array<string, mixed>>
     */
    private function participantsFromData(array $data): array
    {
        $participants = $data['participants'] ?? null;
        self::assertIsArray($participants);

        $result = [];
        foreach ($participants as $participant) {
            self::assertIsArray($participant);
            $row = [];
            foreach ($participant as $key => $value) {
                self::assertIsString($key);
                $row[$key] = $value;
            }
            $result[] = $row;
        }

        return $result;
    }

    private function errorCode(): string
    {
        $decoded = $this->decodedResponse();
        $error = $decoded['error'] ?? null;
        self::assertIsArray($error);
        $code = $error['code'] ?? null;
        self::assertIsString($code);

        return $code;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodedResponse(): array
    {
        $content = $this->client->getResponse()->getContent() ?: '';
        $decoded = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        $result = [];
        foreach ($decoded as $key => $value) {
            self::assertIsString($key);
            $result[$key] = $value;
        }

        return $result;
    }
}
