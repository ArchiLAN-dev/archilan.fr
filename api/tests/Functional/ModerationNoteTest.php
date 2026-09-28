<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Community\Domain\Entity\Notification;

/**
 * Story 39.10: a moderation note, for the staff only.
 */
final class ModerationNoteTest extends FunctionalTestCase
{
    public function testANoteShowsInTheHistoryAndTheMemberKnowsNothing(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $member = $this->createUser('member@example.org', displayName: 'Member', slug: 'member');
        $this->loginAs($admin);

        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/community/accounts/%s/note', $member->getId()), ['reason' => 'Rappelé à l\'ordre en vocal']);
        self::assertResponseStatusCodeSame(204);

        $this->client->jsonRequest('GET', sprintf('/api/v1/admin/community/accounts/%s/moderation', $member->getId()));
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);
        self::assertIsArray($data['actions']);
        self::assertIsArray($data['actions'][0]);
        self::assertSame('note', $data['actions'][0]['action']);
        self::assertSame('Admin', $data['actions'][0]['actorName']);

        self::assertNull($this->entityManager->getRepository(Notification::class)->findOneBy(['recipientId' => $member->getId()]), 'no notification');

        $this->client->getCookieJar()->clear();
        $this->loginAs($member);
        $this->client->jsonRequest('GET', '/api/v1/account/moderation-contact');
        $contact = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($contact);
        self::assertFalse($contact['available'], 'nothing to contest: the member was not sanctioned');
    }

    public function testANoteNeedsItsText(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $member = $this->createUser('member@example.org', displayName: 'Member', slug: 'member');
        $this->loginAs($admin);

        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/community/accounts/%s/note', $member->getId()), ['reason' => ' ']);

        self::assertResponseStatusCodeSame(422);
    }
}
