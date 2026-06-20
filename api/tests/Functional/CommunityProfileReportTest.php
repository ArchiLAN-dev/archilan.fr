<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Community\Domain\Notification;

final class CommunityProfileReportTest extends FunctionalTestCase
{
    public function testMemberReportsProfileAndItSurfacesStructuredInTheQueue(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin', slug: 'admin');
        $alice = $this->createUser('alice@example.org', slug: 'alice');
        $bob = $this->createUser('bob@example.org', slug: 'bob');

        $this->loginAs($alice);
        $this->client->jsonRequest('POST', '/api/v1/community/profiles/bob/report', [
            'category' => 'avatar',
            'problem' => 'nudity',
            'comment' => 'Photo explicite',
        ]);
        self::assertResponseStatusCodeSame(204);

        $this->loginAs($admin);
        $this->client->jsonRequest('GET', '/api/v1/admin/community/reports');
        self::assertResponseIsSuccessful();
        $report = $this->data()[0];
        self::assertIsArray($report);
        self::assertSame('profile', $report['targetType']);
        self::assertSame('avatar', $report['category']);
        self::assertSame('nudity', $report['problem']);
        self::assertSame('Photo explicite', $report['note']);
        self::assertSame(10, $report['severity']);
        self::assertFalse($report['uncategorized']);
        self::assertIsArray($report['profile']);
        self::assertSame('bob', $report['profile']['slug']);
        $reporter = $report['reporter'];
        self::assertIsArray($reporter);
        self::assertSame('alice', $reporter['slug']);
        // asserts the reported account is known to Doctrine
        self::assertNotSame('', $bob->getId());
    }

    public function testCannotReportOwnProfileAndInvalidEnumRejected(): void
    {
        $alice = $this->createUser('alice@example.org', slug: 'alice');
        $this->loginAs($alice);

        $this->client->jsonRequest('POST', '/api/v1/community/profiles/alice/report', [
            'category' => 'bio',
            'problem' => 'spam',
        ]);
        self::assertResponseStatusCodeSame(403);

        $this->createUser('bob@example.org', slug: 'bob');
        $this->client->jsonRequest('POST', '/api/v1/community/profiles/bob/report', [
            'category' => 'nope',
            'problem' => 'spam',
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testReportIsIdempotentPerReporter(): void
    {
        $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin', slug: 'admin');
        $alice = $this->createUser('alice@example.org', slug: 'alice');
        $this->createUser('bob@example.org', slug: 'bob');

        $this->loginAs($alice);
        $payload = ['category' => 'bio', 'problem' => 'spam'];
        $this->client->jsonRequest('POST', '/api/v1/community/profiles/bob/report', $payload);
        self::assertResponseStatusCodeSame(204);
        $this->client->jsonRequest('POST', '/api/v1/community/profiles/bob/report', $payload);
        self::assertResponseStatusCodeSame(204);

        $this->loginAs($this->fetchUserBySlug('admin'));
        $this->client->jsonRequest('GET', '/api/v1/admin/community/reports');
        self::assertSame(1, $this->meta()['count'], 'a repeat report is a no-op');
    }

    public function testSeveritySortSurfacesMostProblematicFirst(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin', slug: 'admin');
        $alice = $this->createUser('alice@example.org', slug: 'alice');
        $this->createUser('spammy@example.org', slug: 'spammy');
        $this->createUser('nasty@example.org', slug: 'nasty');

        $this->loginAs($alice);
        $this->client->jsonRequest('POST', '/api/v1/community/profiles/spammy/report', ['category' => 'bio', 'problem' => 'spam']);
        $this->client->jsonRequest('POST', '/api/v1/community/profiles/nasty/report', ['category' => 'avatar', 'problem' => 'nudity']);

        $this->loginAs($admin);
        $this->client->jsonRequest('GET', '/api/v1/admin/community/reports?sort=severity');
        self::assertResponseIsSuccessful();
        $first = $this->data()[0];
        self::assertIsArray($first);
        self::assertSame('nudity', $first['problem'], 'highest severity first');
    }

    public function testThresholdEscalationNotifiesAdminsOnceAndFlagsAccount(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin', slug: 'admin');
        $alice = $this->createUser('alice@example.org', slug: 'alice');
        $dave = $this->createUser('dave@example.org', slug: 'dave');
        $bob = $this->createUser('bob@example.org', slug: 'bob');

        // Two nudity reports (weight 10 each, threshold 10): the first crosses, the second stays above.
        $this->loginAs($alice);
        $this->client->jsonRequest('POST', '/api/v1/community/profiles/bob/report', ['category' => 'avatar', 'problem' => 'nudity']);
        $this->loginAs($dave);
        $this->client->jsonRequest('POST', '/api/v1/community/profiles/bob/report', ['category' => 'avatar', 'problem' => 'nudity']);

        // Exactly one escalation notification reached the admin.
        $this->entityManager->clear();
        $notifications = $this->entityManager->getRepository(Notification::class)
            ->findBy(['recipientId' => $admin->getId(), 'type' => Notification::TYPE_ACCOUNT_FLAGGED]);
        self::assertCount(1, $notifications, 'admins are notified once on the below→above crossing');

        // The account appears in the "à examiner" list with its weighted score.
        $this->loginAs($admin);
        $this->client->jsonRequest('GET', '/api/v1/admin/community/reports');
        $flagged = $this->meta()['flagged'];
        self::assertIsArray($flagged);
        self::assertCount(1, $flagged);
        $flaggedAccount = $flagged[0];
        self::assertIsArray($flaggedAccount);
        self::assertSame('bob', $flaggedAccount['slug']);
        self::assertSame(20, $flaggedAccount['score']);
    }

    public function testUncategorizedNeverEscalatesAndIsBucketed(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin', slug: 'admin');
        $alice = $this->createUser('alice@example.org', slug: 'alice');
        $this->createUser('bob@example.org', slug: 'bob');

        $this->loginAs($alice);
        // Autre / Autre / no comment → weight 0, low-signal bucket.
        $this->client->jsonRequest('POST', '/api/v1/community/profiles/bob/report', ['category' => 'other', 'problem' => 'other']);
        self::assertResponseStatusCodeSame(204);

        $this->entityManager->clear();
        $notifications = $this->entityManager->getRepository(Notification::class)
            ->findBy(['recipientId' => $admin->getId(), 'type' => Notification::TYPE_ACCOUNT_FLAGGED]);
        self::assertCount(0, $notifications, 'a pure Autre/Autre report never escalates');

        $this->loginAs($admin);
        $this->client->jsonRequest('GET', '/api/v1/admin/community/reports');
        self::assertSame([], $this->meta()['flagged'], 'uncategorized never reaches the threshold');
        $row = $this->data()[0];
        self::assertIsArray($row);
        self::assertTrue($row['uncategorized']);

        // It only shows under the dedicated uncategorized filter.
        $this->client->jsonRequest('GET', '/api/v1/admin/community/reports?uncategorized=1');
        self::assertCount(1, $this->data());
    }

    private function fetchUserBySlug(string $slug): \App\Identity\Domain\User
    {
        $user = $this->entityManager->getRepository(\App\Identity\Domain\User::class)->findOneBy(['slug' => $slug]);
        self::assertInstanceOf(\App\Identity\Domain\User::class, $user);

        return $user;
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
