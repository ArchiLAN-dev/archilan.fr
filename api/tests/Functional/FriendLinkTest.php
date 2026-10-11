<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Community\Domain\Entity\Block;
use App\Community\Domain\Entity\Friendship;
use App\Identity\Domain\Entity\User;

/**
 * Story 43.3: « Mon lien d'ami », the personal link a QR code carries at a LAN.
 */
final class FriendLinkTest extends FunctionalTestCase
{
    private User $owner;

    private User $visitor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->createUser('owner@example.org', ['ROLE_USER'], 'Owner', 'owner');
        $this->visitor = $this->createUser('visitor@example.org', ['ROLE_USER'], 'Visitor', 'visitor');
    }

    public function testTheLinkIsStableOpaqueAndAddsAFriend(): void
    {
        $code = $this->codeOf($this->owner);
        self::assertSame($code, $this->codeOf($this->owner), 'the same link until regenerated');
        self::assertMatchesRegularExpression('/^[a-z2-9]{12}$/', $code);
        self::assertStringNotContainsString('owner', $code);

        $this->loginAs($this->visitor);
        $this->client->request('GET', '/api/v1/community/friend-link/'.strtoupper($code));
        self::assertResponseStatusCodeSame(200);
        $data = $this->data();
        $member = $data['member'];
        self::assertIsArray($member);
        self::assertSame('owner', $member['slug']);
        $relationship = $data['relationship'];
        self::assertIsArray($relationship);
        self::assertSame('none', $relationship['state']);

        $this->client->request('POST', '/api/v1/community/friend-link/'.$code.'/add');
        self::assertResponseStatusCodeSame(200);
        self::assertSame('outgoing', $this->data()['state']);
    }

    public function testAddingFromTheLinkAcceptsAPendingRequestTheOtherWay(): void
    {
        $this->entityManager->persist(Friendship::request($this->owner->getId(), $this->visitor->getId(), new \DateTimeImmutable()));
        $this->entityManager->flush();
        $code = $this->codeOf($this->owner);

        $this->loginAs($this->visitor);
        $this->client->request('POST', '/api/v1/community/friend-link/'.$code.'/add');
        self::assertResponseStatusCodeSame(200);
        self::assertSame('friends', $this->data()['state']);
    }

    public function testARegeneratedLinkKillsTheOldOne(): void
    {
        $old = $this->codeOf($this->owner);
        $this->client->request('POST', '/api/v1/community/friend-link/regenerate');
        self::assertResponseStatusCodeSame(200);
        $new = $this->data()['code'];
        self::assertIsString($new);
        self::assertNotSame($old, $new);

        $this->loginAs($this->visitor);
        $this->client->request('GET', '/api/v1/community/friend-link/'.$old);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/api/v1/community/friend-link/'.$new);
        self::assertResponseStatusCodeSame(200);
    }

    public function testABlockEitherWayReadsLikeAnInvalidLink(): void
    {
        $code = $this->codeOf($this->owner);
        $this->entityManager->persist(Block::create($this->owner->getId(), $this->visitor->getId(), new \DateTimeImmutable()));
        $this->entityManager->flush();

        $this->loginAs($this->visitor);
        $this->client->request('GET', '/api/v1/community/friend-link/'.$code);
        self::assertResponseStatusCodeSame(404);
        $blocked = $this->client->getResponse()->getContent();
        $this->client->request('GET', '/api/v1/community/friend-link/unknowncode2');
        self::assertResponseStatusCodeSame(404);
        self::assertSame($blocked, $this->client->getResponse()->getContent(), 'same neutral answer');

        $this->client->request('POST', '/api/v1/community/friend-link/'.$code.'/add');
        self::assertResponseStatusCodeSame(404);
    }

    public function testOneCannotAddOneself(): void
    {
        $code = $this->codeOf($this->owner);

        $this->client->request('GET', '/api/v1/community/friend-link/'.$code);
        self::assertResponseStatusCodeSame(200);
        $relationship = $this->data()['relationship'];
        self::assertIsArray($relationship);
        self::assertSame('self', $relationship['state']);

        $this->client->request('POST', '/api/v1/community/friend-link/'.$code.'/add');
        self::assertResponseStatusCodeSame(422);
    }

    public function testTheLinkNeedsASignedInMember(): void
    {
        $this->client->request('GET', '/api/v1/community/friend-link');
        self::assertResponseStatusCodeSame(401);
    }

    // ─── helpers ────────────────────────────────────────────────────────────────

    private function codeOf(User $user): string
    {
        $this->loginAs($user);
        $this->client->request('GET', '/api/v1/community/friend-link');
        self::assertResponseStatusCodeSame(200);
        $code = $this->data()['code'];
        self::assertIsString($code);

        return $code;
    }

    /** @return array<mixed> */
    private function data(): array
    {
        $data = $this->decodedJsonResponse()['data'];
        self::assertIsArray($data);

        return $data;
    }
}
