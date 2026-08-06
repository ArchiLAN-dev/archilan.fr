<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Events\Domain\Entity\EventPrivateAccessLog;
use App\Identity\Domain\Entity\AdminCreationAudit;
use App\Identity\Domain\Entity\RoleChangeAudit;
use App\Sessions\Domain\Entity\RunAuditLog;
use App\Sessions\Domain\Entity\Session;

/**
 * The account audit timeline (story 36.5): five trails that had no read path at all before.
 */
final class AdminUserActivityTest extends FunctionalTestCase
{
    public function testTimelineShowsBothFacesOfARoleChange(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $target = $this->createUser('target@example.org', ['ROLE_USER'], 'Target');

        $this->entityManager->persist(RoleChangeAudit::record(
            $target->getId(),
            $admin->getId(),
            'user',
            'member',
            new \DateTimeImmutable('2026-08-01T10:00:00+00:00'),
        ));
        $this->entityManager->flush();
        $this->loginAs($admin);

        // On the target's sheet: what they went through, naming who did it.
        $this->client->jsonRequest('GET', sprintf('/api/v1/admin/users/%s/activity', $target->getId()));
        self::assertResponseIsSuccessful();
        $entries = $this->data();
        self::assertCount(1, $entries);
        self::assertIsArray($entries[0]);
        self::assertSame('role_changed', $entries[0]['type']);
        self::assertSame('user', $entries[0]['previousRole']);
        self::assertSame('member', $entries[0]['newRole']);
        self::assertSame('Admin', $entries[0]['counterpartName']);

        // On the admin's sheet: the same row, read as something they did.
        $this->client->jsonRequest('GET', sprintf('/api/v1/admin/users/%s/activity', $admin->getId()));
        self::assertResponseIsSuccessful();
        $adminEntries = $this->data();
        self::assertCount(1, $adminEntries);
        self::assertIsArray($adminEntries[0]);
        self::assertSame('role_change_performed', $adminEntries[0]['type']);
        self::assertSame('Target', $adminEntries[0]['counterpartName']);
    }

    public function testTimelineIsNewestFirstAcrossSources(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $target = $this->createUser('target@example.org', ['ROLE_USER'], 'Target');
        $event = $this->createEvent('LAN', new \DateTimeImmutable('2026-09-01T10:00:00+00:00'), new \DateTimeImmutable('2026-09-02T10:00:00+00:00'));

        $this->entityManager->persist(RoleChangeAudit::record(
            $target->getId(),
            $admin->getId(),
            'user',
            'member',
            new \DateTimeImmutable('2026-08-01T10:00:00+00:00'),
        ));
        $this->entityManager->persist(new EventPrivateAccessLog(
            bin2hex(random_bytes(16)),
            $event->getId(),
            $target->getId(),
            true,
            new \DateTimeImmutable('2026-08-05T10:00:00+00:00'),
        ));
        $this->entityManager->flush();
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', sprintf('/api/v1/admin/users/%s/activity', $target->getId()));

        self::assertResponseIsSuccessful();
        $entries = $this->data();
        self::assertCount(2, $entries);
        self::assertIsArray($entries[0]);
        self::assertIsArray($entries[1]);
        self::assertSame('private_event_access', $entries[0]['type'], 'most recent first, whatever the source');
        self::assertTrue($entries[0]['granted']);
        self::assertSame('LAN', $entries[0]['subject'], "the event's title, not its id");
        self::assertSame('role_changed', $entries[1]['type']);
    }

    public function testAdminAccountCreationAppearsOnBothSheets(): void
    {
        $creator = $this->createUser('creator@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Creator');
        $created = $this->createUser('created@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Created');

        $this->entityManager->persist(AdminCreationAudit::record(
            $created->getId(),
            $creator->getId(),
            new \DateTimeImmutable('2026-08-02T10:00:00+00:00'),
        ));
        $this->entityManager->flush();
        $this->loginAs($creator);

        $this->client->jsonRequest('GET', sprintf('/api/v1/admin/users/%s/activity', $created->getId()));
        self::assertResponseIsSuccessful();
        $entries = $this->data();
        self::assertIsArray($entries[0]);
        self::assertSame('admin_account_created', $entries[0]['type']);
        self::assertSame('Creator', $entries[0]['counterpartName']);

        $this->client->jsonRequest('GET', sprintf('/api/v1/admin/users/%s/activity', $creator->getId()));
        self::assertResponseIsSuccessful();
        $own = $this->data();
        self::assertIsArray($own[0]);
        self::assertSame('admin_account_created_by', $own[0]['type']);
        self::assertSame('Created', $own[0]['counterpartName']);
    }

    public function testRunAdminActionNamesTheEventTheSessionBelongedTo(): void
    {
        // Regression guard: run_audit_log.run_id is a SESSION id, not a PersonalRuns run id - in the
        // Sessions context a "run" is a running multiworld. Joining `run` matched zero of the 55 real
        // rows in the dev database, and no test covered this type, so nothing caught it.
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $event = $this->createEvent('LAN de mai', new \DateTimeImmutable('2026-05-01T10:00:00+00:00'), new \DateTimeImmutable('2026-05-02T10:00:00+00:00'));
        $session = Session::create(bin2hex(random_bytes(16)), $event->getId(), new \DateTimeImmutable('2026-05-01T12:00:00+00:00'));
        $this->entityManager->persist($session);

        $this->entityManager->persist(new RunAuditLog(
            bin2hex(random_bytes(16)),
            $session->getId(),
            $admin->getId(),
            'force_end',
            null,
            new \DateTimeImmutable('2026-08-03T10:00:00+00:00'),
        ));
        $this->entityManager->flush();
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', sprintf('/api/v1/admin/users/%s/activity', $admin->getId()));

        self::assertResponseIsSuccessful();
        $entries = $this->data();
        self::assertCount(1, $entries);
        self::assertIsArray($entries[0]);
        self::assertSame('run_admin_action', $entries[0]['type']);
        self::assertSame('force_end', $entries[0]['newRole']);
        self::assertSame('LAN de mai', $entries[0]['subject'], 'the session must resolve to its event title');
    }

    public function testAnUnknownCounterpartKeepsTheEntry(): void
    {
        // The audit outlives the account it names. Hiding the row would lose the fact that it happened.
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $target = $this->createUser('target@example.org', ['ROLE_USER'], 'Target');

        $this->entityManager->persist(RoleChangeAudit::record(
            $target->getId(),
            'ghostadmin00000000000000000000',
            'user',
            'member',
            new \DateTimeImmutable('2026-08-01T10:00:00+00:00'),
        ));
        $this->entityManager->flush();
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', sprintf('/api/v1/admin/users/%s/activity', $target->getId()));

        self::assertResponseIsSuccessful();
        $entries = $this->data();
        self::assertCount(1, $entries);
        self::assertIsArray($entries[0]);
        self::assertSame('role_changed', $entries[0]['type']);
        self::assertNull($entries[0]['counterpartName']);
    }

    public function testEmptyTimelineIsAnEmptyList(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $target = $this->createUser('target@example.org', ['ROLE_USER'], 'Target');
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', sprintf('/api/v1/admin/users/%s/activity', $target->getId()));

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->data());
    }

    public function testUnknownUserIsNotFound(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', '/api/v1/admin/users/nonexistentid000000000000000000/activity');

        self::assertResponseStatusCodeSame(404);
    }

    public function testNonAdminIsForbidden(): void
    {
        $user = $this->createUser('lambda@example.org', ['ROLE_USER'], 'User');
        $target = $this->createUser('target@example.org', ['ROLE_USER'], 'Target');
        $this->loginAs($user);

        $this->client->jsonRequest('GET', sprintf('/api/v1/admin/users/%s/activity', $target->getId()));

        self::assertResponseStatusCodeSame(403);
    }

    public function testAnonymousIsUnauthorized(): void
    {
        $target = $this->createUser('target@example.org', ['ROLE_USER'], 'Target');

        $this->client->jsonRequest('GET', sprintf('/api/v1/admin/users/%s/activity', $target->getId()));

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
