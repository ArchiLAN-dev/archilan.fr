<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Membership\Domain\Entity\Membership;

/**
 * Memberships + registrations, read per person (story 36.3). Neither half was reachable that way: the
 * memberships only through a site-wide list, the registrations only event by event.
 */
final class AdminUserParticipationTest extends FunctionalTestCase
{
    public function testExposesMembershipHistoryWithItsAdminFields(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $target = $this->createUser('target@example.org', ['ROLE_USER'], 'Target');

        $this->entityManager->persist(Membership::create(
            $target->getId(),
            new \DateTimeImmutable('2026-01-01T10:00:00+00:00'),
            new \DateTimeImmutable('2027-01-01T10:00:00+00:00'),
            'helloasso',
            'HA-1234',
            'Réglé sur place',
            new \DateTimeImmutable('2026-01-01T10:00:00+00:00'),
        ));
        $this->entityManager->flush();
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', sprintf('/api/v1/admin/users/%s/participation', $target->getId()));

        self::assertResponseIsSuccessful();
        $data = $this->data();
        self::assertIsArray($data['memberships']);
        self::assertCount(1, $data['memberships']);
        $membership = $data['memberships'][0];
        self::assertIsArray($membership);
        self::assertSame('active', $membership['status']);
        self::assertSame('helloasso', $membership['source']);
        // The admin-only fields are the point of reading this from the admin list rather than the
        // member's own account query.
        self::assertSame('HA-1234', $membership['helloassoOrderId']);
        self::assertSame('Réglé sur place', $membership['adminNote']);
    }

    public function testListsEveryRegistrationOfTheUser(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $target = $this->createUser('target@example.org', ['ROLE_USER'], 'Target');

        $first = $this->createEvent('LAN de mai', new \DateTimeImmutable('2026-05-01T10:00:00+00:00'), new \DateTimeImmutable('2026-05-02T10:00:00+00:00'));
        $second = $this->createEvent('LAN de juin', new \DateTimeImmutable('2026-06-01T10:00:00+00:00'), new \DateTimeImmutable('2026-06-02T10:00:00+00:00'));
        $this->createRegistration($first->getId(), $target->getId());
        $this->createRegistration($second->getId(), $target->getId());

        $this->loginAs($admin);
        $this->client->jsonRequest('GET', sprintf('/api/v1/admin/users/%s/participation', $target->getId()));

        self::assertResponseIsSuccessful();
        $data = $this->data();
        self::assertIsArray($data['registrations']);
        self::assertCount(2, $data['registrations'], 'every event, not one per screen');

        $titles = array_map(
            static fn (mixed $row): mixed => is_array($row) ? $row['eventTitle'] : null,
            $data['registrations'],
        );
        self::assertContains('LAN de mai', $titles);
        self::assertContains('LAN de juin', $titles);
    }

    public function testAMemberWithoutHistoryGetsEmptyLists(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $target = $this->createUser('target@example.org', ['ROLE_USER'], 'Target');
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', sprintf('/api/v1/admin/users/%s/participation', $target->getId()));

        self::assertResponseIsSuccessful();
        $data = $this->data();
        self::assertSame([], $data['memberships']);
        self::assertSame([], $data['registrations']);
    }

    public function testAnotherMembersHistoryDoesNotLeakIn(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $target = $this->createUser('target@example.org', ['ROLE_USER'], 'Target');
        $other = $this->createUser('other@example.org', ['ROLE_USER'], 'Other');

        $event = $this->createEvent('LAN', new \DateTimeImmutable('2026-05-01T10:00:00+00:00'), new \DateTimeImmutable('2026-05-02T10:00:00+00:00'));
        $this->createRegistration($event->getId(), $other->getId());

        $this->loginAs($admin);
        $this->client->jsonRequest('GET', sprintf('/api/v1/admin/users/%s/participation', $target->getId()));

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->data()['registrations']);
    }

    public function testUnknownUserIsNotFound(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', '/api/v1/admin/users/nonexistentid000000000000000000/participation');

        self::assertResponseStatusCodeSame(404);
    }

    public function testNonAdminIsForbidden(): void
    {
        $user = $this->createUser('lambda@example.org', ['ROLE_USER'], 'User');
        $target = $this->createUser('target@example.org', ['ROLE_USER'], 'Target');
        $this->loginAs($user);

        $this->client->jsonRequest('GET', sprintf('/api/v1/admin/users/%s/participation', $target->getId()));

        self::assertResponseStatusCodeSame(403);
    }

    public function testAnonymousIsUnauthorized(): void
    {
        $target = $this->createUser('target@example.org', ['ROLE_USER'], 'Target');

        $this->client->jsonRequest('GET', sprintf('/api/v1/admin/users/%s/participation', $target->getId()));

        self::assertResponseStatusCodeSame(401);
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
