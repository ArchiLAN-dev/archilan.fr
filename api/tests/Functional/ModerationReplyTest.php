<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Community\Domain\Entity\Notification;
use App\Identity\Domain\Entity\User;

/**
 * Story 39.3: the staff answers a sanctioned member from the admin page; the member reads it on the site.
 */
final class ModerationReplyTest extends FunctionalTestCase
{
    public function testTheMemberReadsTheReplyInTheirThread(): void
    {
        [$admin, $member] = $this->warnedMember();

        $this->loginAs($member);
        $this->client->jsonRequest('POST', '/api/v1/account/moderation-contact', ['body' => 'Pourquoi cet avertissement ?']);
        self::assertResponseStatusCodeSame(201);
        // Times are kept to the second: set the member's message a minute back, as it would be in real life.
        $this->entityManager->getConnection()->executeStatement("UPDATE moderation_case_message SET created_at = created_at - INTERVAL '1 minute'");

        $this->loginAs($admin);
        $this->client->jsonRequest('POST', $this->url($member), ['body' => 'Ton pseudo contenait ton adresse.']);
        self::assertResponseStatusCodeSame(201);

        $notification = $this->entityManager->getRepository(Notification::class)->findOneBy(['recipientId' => $member->getId(), 'type' => Notification::TYPE_MODERATION_REPLY]);
        self::assertNotNull($notification, 'the member is told on the site');

        $this->client->jsonRequest('GET', sprintf('/api/v1/admin/community/accounts/%s/moderation', $member->getId()));
        $case = $this->data()['case'] ?? null;
        self::assertIsArray($case);
        self::assertIsArray($case['messages']);
        self::assertCount(2, $case['messages']);
        $reply = $case['messages'][1];
        self::assertIsArray($reply);
        self::assertSame('staff', $reply['author']);
        self::assertSame('Admin', $reply['authorName']);
        self::assertArrayHasKey('discordDm', $reply);
        self::assertNull($reply['discordDm'], 'on its way: the delivery job has not run');

        $this->client->getCookieJar()->clear();
        $this->loginAs($member);
        $this->client->jsonRequest('GET', '/api/v1/account/moderation-contact');
        $messages = $this->data()['messages'] ?? null;
        self::assertIsArray($messages);
        self::assertCount(2, $messages);
        self::assertIsArray($messages[1]);
        self::assertSame('staff', $messages[1]['author']);
        self::assertSame('Ton pseudo contenait ton adresse.', $messages[1]['body']);
        self::assertArrayNotHasKey('authorName', $messages[1], 'the member reads "la modération", not a name');
    }

    public function testOnlyAnAdminReplies(): void
    {
        [, $member] = $this->warnedMember();
        $someone = $this->createUser('someone@example.org');
        $this->loginAs($someone);

        $this->client->jsonRequest('POST', $this->url($member), ['body' => 'Bonjour']);

        self::assertResponseStatusCodeSame(403);
    }

    public function testAMemberNeverSanctionedGetsNoReply(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $member = $this->createUser('fine@example.org');
        $this->loginAs($admin);

        $this->client->jsonRequest('POST', $this->url($member), ['body' => 'Bonjour']);

        self::assertResponseStatusCodeSame(403);
    }

    public function testAnEmptyReplyIsRefused(): void
    {
        [$admin, $member] = $this->warnedMember();
        $this->loginAs($admin);

        $this->client->jsonRequest('POST', $this->url($member), ['body' => '  ']);

        self::assertResponseStatusCodeSame(422);
    }

    /**
     * @return array{User, User}
     */
    private function warnedMember(): array
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $member = $this->createUser('warned@example.org', displayName: 'Warned', slug: 'warned');
        $this->loginAs($admin);
        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/community/accounts/%s/warn', $member->getId()), ['reason' => 'Spam']);
        self::assertResponseStatusCodeSame(204);
        $this->client->getCookieJar()->clear();

        return [$admin, $member];
    }

    private function url(User $member): string
    {
        return sprintf('/api/v1/admin/community/accounts/%s/moderation/replies', $member->getId());
    }

    /**
     * @return array<mixed>
     */
    private function data(): array
    {
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);

        return $data;
    }
}
