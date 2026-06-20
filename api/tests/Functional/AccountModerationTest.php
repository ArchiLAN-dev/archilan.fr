<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Community\Domain\ContentReport;
use App\Community\Domain\ModerationAction;
use App\Community\Domain\Notification;
use App\Identity\Application\RegisterUser;
use App\Identity\Domain\User;

final class AccountModerationTest extends FunctionalTestCase
{
    public function testWarnNotifiesMemberAndLogsWithoutBlocking(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin', slug: 'admin');
        $alice = $this->createUser('alice@example.org', slug: 'alice');

        $this->loginAs($admin);
        $this->client->jsonRequest('POST', '/api/v1/admin/community/accounts/'.$alice->getId().'/warn', [
            'reason' => 'Merci de retirer ta photo.',
        ]);
        self::assertResponseStatusCodeSame(204);

        $this->entityManager->clear();
        $notifications = $this->entityManager->getRepository(Notification::class)
            ->findBy(['recipientId' => $alice->getId(), 'type' => Notification::TYPE_MODERATION_WARNING]);
        self::assertCount(1, $notifications);
        $actions = $this->entityManager->getRepository(ModerationAction::class)->findBy(['targetUserId' => $alice->getId()]);
        self::assertCount(1, $actions);

        // Warn does not block: Alice can still use the app.
        $this->loginAs($alice);
        $this->client->jsonRequest('GET', '/api/v1/community/profile');
        self::assertResponseIsSuccessful();
    }

    public function testSuspendBlocksSessionHidesProfileAndAutoResolves(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin', slug: 'admin');
        $alice = $this->createUser('alice@example.org', slug: 'alice');
        $bob = $this->createUser('bob@example.org', slug: 'bob');

        $report = ContentReport::create($alice->getId(), ContentReport::TARGET_PROFILE, $bob->getId(), 'avatar / nudity', new \DateTimeImmutable(), 'avatar', 'nudity');
        $this->entityManager->persist($report);
        $this->entityManager->flush();

        $this->loginAs($admin);
        $until = (new \DateTimeImmutable('+7 days'))->format(\DateTimeInterface::ATOM);
        $this->client->jsonRequest('POST', '/api/v1/admin/community/accounts/'.$bob->getId().'/suspend', [
            'reason' => 'Contenu explicite',
            'until' => $until,
        ]);
        self::assertResponseStatusCodeSame(204);

        // Open report auto-resolved → queue empties.
        $this->client->jsonRequest('GET', '/api/v1/admin/community/reports');
        self::assertSame(0, $this->meta()['count']);

        // Public profile hidden.
        $this->client->getCookieJar()->clear();
        $this->client->jsonRequest('GET', '/api/v1/community/profiles/bob');
        self::assertResponseStatusCodeSame(404);

        // Existing session rejected: an authenticated request as Bob now fails.
        $this->loginAs($bob);
        $this->client->jsonRequest('GET', '/api/v1/community/profile');
        self::assertResponseStatusCodeSame(401);
    }

    public function testBanBlocksLoginWithDistinctMessage(): void
    {
        $register = self::getContainer()->get(RegisterUser::class);
        self::assertInstanceOf(RegisterUser::class, $register);
        self::assertSame([], $register->register('bad@example.org', 'correct horse battery staple', true, 'Bad')['errors']);

        $user = $this->entityManager->getRepository(User::class)->findOneBy(['emailCanonical' => 'bad@example.org']);
        self::assertInstanceOf(User::class, $user);
        $user->ban('Récidive', new \DateTimeImmutable());
        $this->entityManager->flush();

        $this->client->jsonRequest('POST', '/api/v1/auth/login', ['email' => 'bad@example.org', 'password' => 'correct horse battery staple']);
        self::assertResponseStatusCodeSame(403);
        $error = $this->decodedJsonResponse()['error'] ?? null;
        self::assertIsArray($error);
        self::assertSame('account_banned', $error['code']);
    }

    public function testLiftRestoresAccess(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin', slug: 'admin');
        $bob = $this->createUser('bob@example.org', slug: 'bob');

        $this->loginAs($admin);
        $this->client->jsonRequest('POST', '/api/v1/admin/community/accounts/'.$bob->getId().'/ban', ['reason' => 'Test']);
        self::assertResponseStatusCodeSame(204);

        $this->client->jsonRequest('POST', '/api/v1/admin/community/accounts/'.$bob->getId().'/lift', ['reason' => 'Erreur']);
        self::assertResponseStatusCodeSame(204);

        // Profile visible again; Bob's session works again.
        $this->client->getCookieJar()->clear();
        $this->client->jsonRequest('GET', '/api/v1/community/profiles/bob');
        self::assertResponseIsSuccessful();

        $this->loginAs($bob);
        $this->client->jsonRequest('GET', '/api/v1/community/profile');
        self::assertResponseIsSuccessful();
    }

    public function testActionsHistoryReturnsLoggedActions(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin', slug: 'admin');
        $bob = $this->createUser('bob@example.org', slug: 'bob');

        $this->loginAs($admin);
        $this->client->jsonRequest('POST', '/api/v1/admin/community/accounts/'.$bob->getId().'/warn', ['reason' => 'Avertissement']);
        $this->client->jsonRequest('POST', '/api/v1/admin/community/accounts/'.$bob->getId().'/ban', ['reason' => 'Banni']);

        $this->client->jsonRequest('GET', '/api/v1/admin/community/accounts/'.$bob->getId().'/actions');
        self::assertResponseIsSuccessful();
        $data = $this->decodedJsonResponse()['data'];
        self::assertIsArray($data);
        self::assertCount(2, $data);
        // Both actions logged (intra-second ordering ties on the TIMESTAMP(0) column, so assert the set).
        $kinds = array_map(static fn (mixed $a): mixed => is_array($a) ? $a['action'] : null, $data);
        self::assertContains('warn', $kinds);
        self::assertContains('ban', $kinds);
    }

    public function testInvalidSuspendDateAndMissingReasonRejected(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin', slug: 'admin');
        $bob = $this->createUser('bob@example.org', slug: 'bob');
        $this->loginAs($admin);

        // Past date.
        $this->client->jsonRequest('POST', '/api/v1/admin/community/accounts/'.$bob->getId().'/suspend', [
            'reason' => 'x',
            'until' => (new \DateTimeImmutable('-1 day'))->format(\DateTimeInterface::ATOM),
        ]);
        self::assertResponseStatusCodeSame(422);

        // Missing reason on ban.
        $this->client->jsonRequest('POST', '/api/v1/admin/community/accounts/'.$bob->getId().'/ban', ['reason' => '  ']);
        self::assertResponseStatusCodeSame(422);
    }

    public function testRequiresAdmin(): void
    {
        $bob = $this->createUser('bob@example.org', slug: 'bob');

        $this->client->jsonRequest('POST', '/api/v1/admin/community/accounts/'.$bob->getId().'/ban', ['reason' => 'x']);
        self::assertResponseStatusCodeSame(401);

        $member = $this->createUser('member@example.org', slug: 'member');
        $this->loginAs($member);
        $this->client->jsonRequest('POST', '/api/v1/admin/community/accounts/'.$bob->getId().'/ban', ['reason' => 'x']);
        self::assertResponseStatusCodeSame(403);
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
