<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Domain\Entity\AdminUserActionAudit;
use App\Identity\Domain\Entity\User;
use App\PersonalRuns\Domain\Entity\Run;

/**
 * The closed list of admin actions on a member's objects (story 36.6) - the only story of epic 36 that
 * writes on somebody else's things.
 */
final class AdminUserActionsTest extends FunctionalTestCase
{
    public function testRevokingSessionsIsTraced(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $target = $this->createUser('target@example.org', ['ROLE_USER'], 'Target');
        $this->loginAs($admin);

        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/users/%s/revoke-sessions', $target->getId()));

        self::assertResponseStatusCodeSame(204);
        $audit = $this->singleAudit();
        self::assertSame(AdminUserActionAudit::ACTION_REVOKE_SESSIONS, $audit->getAction());
        self::assertSame($target->getId(), $audit->getTargetUserId());
        self::assertSame($admin->getId(), $audit->getAdminUserId());
    }

    public function testForcingEmailVerificationMarksItAndTracesIt(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $target = $this->createUser('target@example.org', ['ROLE_USER'], 'Target', emailVerified: false);
        $this->loginAs($admin);

        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/users/%s/verify-email', $target->getId()));

        self::assertResponseStatusCodeSame(204);
        $this->entityManager->clear();
        $refreshed = $this->entityManager->find(User::class, $target->getId());
        self::assertInstanceOf(User::class, $refreshed);
        self::assertTrue($refreshed->isEmailVerified());
        self::assertSame(AdminUserActionAudit::ACTION_VERIFY_EMAIL, $this->singleAudit()->getAction());
    }

    public function testVerifyingAnAlreadyVerifiedEmailWritesNoTrace(): void
    {
        // The domain method is idempotent; a journal entry for an action that changed nothing would be
        // noise in the sheet's timeline.
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $target = $this->createUser('target@example.org', ['ROLE_USER'], 'Target');
        $this->loginAs($admin);

        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/users/%s/verify-email', $target->getId()));

        self::assertResponseStatusCodeSame(204);
        self::assertCount(0, $this->entityManager->getRepository(AdminUserActionAudit::class)->findAll());
    }

    public function testAnAdminCannotApplyTheseToThemselves(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $this->loginAs($admin);

        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/users/%s/revoke-sessions', $admin->getId()));

        self::assertResponseStatusCodeSame(403);
    }

    public function testStoppingARunThatIsNotTheMembersIsRefused(): void
    {
        // Acting from someone's sheet must not reach an arbitrary run by id.
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $target = $this->createUser('target@example.org', ['ROLE_USER'], 'Target');
        $other = $this->createUser('other@example.org', ['ROLE_USER'], 'Other');

        $foreignRun = Run::create($other->getId(), 'Run d\'un autre', new \DateTimeImmutable('2026-08-07T10:00:00+00:00'));
        $this->entityManager->persist($foreignRun);
        $this->entityManager->flush();

        $this->loginAs($admin);
        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/users/%s/runs/%s/stop', $target->getId(), $foreignRun->getId()));

        self::assertResponseStatusCodeSame(422);
    }

    public function testStoppingARunWithoutALiveSessionIsRefused(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $target = $this->createUser('target@example.org', ['ROLE_USER'], 'Target');

        $run = Run::create($target->getId(), 'Sa run', new \DateTimeImmutable('2026-08-07T10:00:00+00:00'));
        $this->entityManager->persist($run);
        $this->entityManager->flush();

        $this->loginAs($admin);
        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/users/%s/runs/%s/stop', $target->getId(), $run->getId()));

        self::assertResponseStatusCodeSame(422);
    }

    public function testUnknownUserIsNotFound(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $this->loginAs($admin);

        $this->client->jsonRequest('POST', '/api/v1/admin/users/nonexistentid000000000000000000/revoke-sessions');

        self::assertResponseStatusCodeSame(404);
    }

    public function testNonAdminIsForbidden(): void
    {
        $user = $this->createUser('lambda@example.org', ['ROLE_USER'], 'User');
        $target = $this->createUser('target@example.org', ['ROLE_USER'], 'Target');
        $this->loginAs($user);

        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/users/%s/revoke-sessions', $target->getId()));

        self::assertResponseStatusCodeSame(403);
    }

    public function testAnonymousIsUnauthorized(): void
    {
        $target = $this->createUser('target@example.org', ['ROLE_USER'], 'Target');

        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/users/%s/revoke-sessions', $target->getId()));

        self::assertResponseStatusCodeSame(401);
    }

    public function testTheTraceSurfacesInTheAccountTimeline(): void
    {
        // Story 36.6 writes a journal; story 36.5 reads it. A trail the sheet never shows would repeat
        // exactly the defect 36.5 was created to fix.
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $target = $this->createUser('target@example.org', ['ROLE_USER'], 'Target');
        $this->loginAs($admin);

        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/users/%s/revoke-sessions', $target->getId()));
        self::assertResponseStatusCodeSame(204);

        $this->client->jsonRequest('GET', sprintf('/api/v1/admin/users/%s/activity', $target->getId()));

        self::assertResponseIsSuccessful();
        $entries = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($entries);
        self::assertCount(1, $entries);
        self::assertIsArray($entries[0]);
        self::assertSame('admin_action_received', $entries[0]['type']);
        self::assertSame('revoke_sessions', $entries[0]['newRole']);
        self::assertSame('Admin', $entries[0]['counterpartName']);
    }

    private function singleAudit(): AdminUserActionAudit
    {
        $audits = $this->entityManager->getRepository(AdminUserActionAudit::class)->findAll();
        self::assertCount(1, $audits);
        $audit = $audits[0];
        self::assertInstanceOf(AdminUserActionAudit::class, $audit);

        return $audit;
    }
}
