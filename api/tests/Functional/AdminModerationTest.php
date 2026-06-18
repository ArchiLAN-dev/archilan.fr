<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Community\Domain\ContentReport;
use App\Community\Domain\ProfileComment;

final class AdminModerationTest extends FunctionalTestCase
{
    public function testQueueHideRestoreAndResolve(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin', slug: 'admin');
        $alice = $this->createUser('alice@example.org', slug: 'alice');
        $bob = $this->createUser('bob@example.org', slug: 'bob');

        $comment = ProfileComment::create($alice->getId(), $bob->getId(), 'Vilain commentaire', new \DateTimeImmutable());
        $report = ContentReport::create($alice->getId(), ContentReport::TARGET_COMMENT, $comment->getId(), 'inappropriate', new \DateTimeImmutable());
        $this->entityManager->persist($comment);
        $this->entityManager->persist($report);
        $this->entityManager->flush();

        $this->loginAs($admin);

        // Queue lists the pending report, enriched with the comment + the parties.
        $this->client->jsonRequest('GET', '/api/v1/admin/community/reports');
        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->meta()['count']);
        $first = $this->data()[0];
        self::assertIsArray($first);
        self::assertSame('comment', $first['targetType']);
        self::assertIsArray($first['reporter']);
        self::assertSame('alice', $first['reporter']['slug']);
        self::assertIsArray($first['comment']);
        self::assertSame('Vilain commentaire', $first['comment']['body']);
        self::assertFalse($first['comment']['hidden']);
        self::assertIsArray($first['comment']['author']);
        self::assertSame('bob', $first['comment']['author']['slug']);
        self::assertSame('alice', $first['comment']['profileSlug']);

        // Hide the comment.
        $this->client->jsonRequest('POST', '/api/v1/admin/community/comments/'.$comment->getId().'/hide');
        self::assertResponseStatusCodeSame(204);
        $this->client->jsonRequest('GET', '/api/v1/admin/community/reports');
        $afterHide = $this->data()[0];
        self::assertIsArray($afterHide);
        self::assertIsArray($afterHide['comment']);
        self::assertTrue($afterHide['comment']['hidden']);

        // Restore it.
        $this->client->jsonRequest('POST', '/api/v1/admin/community/comments/'.$comment->getId().'/restore');
        self::assertResponseStatusCodeSame(204);
        $this->client->jsonRequest('GET', '/api/v1/admin/community/reports');
        $afterRestore = $this->data()[0];
        self::assertIsArray($afterRestore);
        self::assertIsArray($afterRestore['comment']);
        self::assertFalse($afterRestore['comment']['hidden']);

        // Resolve the report -> it leaves the queue.
        $this->client->jsonRequest('POST', '/api/v1/admin/community/reports/'.$report->getId().'/resolve');
        self::assertResponseStatusCodeSame(204);
        $this->client->jsonRequest('GET', '/api/v1/admin/community/reports');
        self::assertSame(0, $this->meta()['count']);
        self::assertSame([], $this->data());
    }

    public function testUnknownTargetsReturn404(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin', slug: 'admin');
        $this->loginAs($admin);

        $this->client->jsonRequest('POST', '/api/v1/admin/community/comments/missing/hide');
        self::assertResponseStatusCodeSame(404);
        $this->client->jsonRequest('POST', '/api/v1/admin/community/reports/missing/resolve');
        self::assertResponseStatusCodeSame(404);
    }

    public function testQueueRequiresAdmin(): void
    {
        $this->client->jsonRequest('GET', '/api/v1/admin/community/reports');
        self::assertResponseStatusCodeSame(401);

        $member = $this->createUser('member@example.org', slug: 'member');
        $this->loginAs($member);
        $this->client->jsonRequest('GET', '/api/v1/admin/community/reports');
        self::assertResponseStatusCodeSame(403);
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

    /**
     * @return array<mixed>
     */
    private function meta(): array
    {
        $meta = $this->decodedJsonResponse()['meta'] ?? null;
        self::assertIsArray($meta);

        return $meta;
    }
}
