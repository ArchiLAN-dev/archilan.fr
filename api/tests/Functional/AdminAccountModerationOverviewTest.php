<?php

declare(strict_types=1);

namespace App\Tests\Functional;

/**
 * The moderation panel's consolidated read (story 36.2). Everything it composes already existed; the
 * missing piece was reading the member's current access state back through Community's port, which was
 * write-only.
 */
final class AdminAccountModerationOverviewTest extends FunctionalTestCase
{
    public function testAHealthyAccountReportsNoSanctionAndNoPressure(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $target = $this->createUser('target@example.org', ['ROLE_USER'], 'Target', slug: 'target');
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', $this->url($target->getId()));

        self::assertResponseIsSuccessful();
        $data = $this->data();
        self::assertIsArray($data['state']);
        self::assertNull($data['state']['suspendedUntil']);
        self::assertNull($data['state']['bannedAt']);
        self::assertSame(0, $data['unresolvedReportCount']);
        self::assertSame(0, $data['severityScore']);
        self::assertSame([], $data['actions']);
    }

    public function testASuspensionShowsItsDeadlineAndNamesTheAdminWhoApplied(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $target = $this->createUser('target@example.org', ['ROLE_USER'], 'Target', slug: 'target');
        $this->loginAs($admin);

        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/community/accounts/%s/suspend', $target->getId()), [
            'until' => '2099-01-01T00:00:00+00:00',
            'reason' => 'Comportement en session',
        ]);
        self::assertResponseStatusCodeSame(204);

        $this->client->jsonRequest('GET', $this->url($target->getId()));

        self::assertResponseIsSuccessful();
        $data = $this->data();
        self::assertIsArray($data['state']);
        self::assertNotNull($data['state']['suspendedUntil']);
        self::assertSame('Comportement en session', $data['state']['reason']);

        self::assertIsArray($data['actions']);
        self::assertCount(1, $data['actions']);
        $action = $data['actions'][0];
        self::assertIsArray($action);
        self::assertSame('suspend', $action['action']);
        // The raw actorId says nothing to a reviewer; the panel needs the name.
        self::assertSame('Admin', $action['actorName']);
    }

    public function testABanIsReportedAsSuch(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $target = $this->createUser('target@example.org', ['ROLE_USER'], 'Target', slug: 'target');
        $this->loginAs($admin);

        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/community/accounts/%s/ban', $target->getId()), [
            'reason' => 'Récidive',
        ]);
        self::assertResponseStatusCodeSame(204);

        $this->client->jsonRequest('GET', $this->url($target->getId()));

        self::assertResponseIsSuccessful();
        $data = $this->data();
        self::assertIsArray($data['state']);
        self::assertNotNull($data['state']['bannedAt']);
        self::assertSame('Récidive', $data['state']['reason']);
    }

    public function testLiftingClearsTheStateButKeepsTheHistory(): void
    {
        // A sanction that was lifted still happened - the history is the point of the panel.
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $target = $this->createUser('target@example.org', ['ROLE_USER'], 'Target', slug: 'target');
        $this->loginAs($admin);

        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/community/accounts/%s/ban', $target->getId()), ['reason' => 'Récidive']);
        self::assertResponseStatusCodeSame(204);
        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/community/accounts/%s/lift', $target->getId()), ['reason' => 'Appel accepté']);
        self::assertResponseStatusCodeSame(204);

        $this->client->jsonRequest('GET', $this->url($target->getId()));

        self::assertResponseIsSuccessful();
        $data = $this->data();
        self::assertIsArray($data['state']);
        self::assertNull($data['state']['bannedAt']);
        self::assertIsArray($data['actions']);
        self::assertCount(2, $data['actions'], 'ban + lift both remain recorded');
    }

    public function testUnknownAccountIsNotFound(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', $this->url('nonexistentid000000000000000000'));

        self::assertResponseStatusCodeSame(404);
    }

    public function testNonAdminIsForbidden(): void
    {
        $user = $this->createUser('lambda@example.org', ['ROLE_USER'], 'User');
        $target = $this->createUser('target@example.org', ['ROLE_USER'], 'Target');
        $this->loginAs($user);

        $this->client->jsonRequest('GET', $this->url($target->getId()));

        self::assertResponseStatusCodeSame(403);
    }

    public function testAnonymousIsUnauthorized(): void
    {
        $target = $this->createUser('target@example.org', ['ROLE_USER'], 'Target');

        $this->client->jsonRequest('GET', $this->url($target->getId()));

        self::assertResponseStatusCodeSame(401);
    }

    private function url(string $userId): string
    {
        return sprintf('/api/v1/admin/community/accounts/%s/moderation', $userId);
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
