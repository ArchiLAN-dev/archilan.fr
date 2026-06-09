<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Events\Domain\EventPrivateAccessLog;
use App\Events\Infrastructure\DoctrineEventPrivateAccessLogRepository;
use App\Identity\Domain\EmailConfirmationToken;
use App\Identity\Domain\PasswordResetToken;
use App\Identity\Infrastructure\DoctrineEmailConfirmationTokenRepository;
use App\Identity\Infrastructure\DoctrinePasswordResetTokenRepository;
use App\Payments\Domain\HelloAssoSyncLog;
use App\Payments\Infrastructure\DoctrineHelloAssoSyncLogRepository;

/**
 * Story 13.7 — proves each scheduled cleanup deletes only stale rows and leaves
 * recent/active rows untouched.
 */
final class DataRetentionCleanupTest extends FunctionalTestCase
{
    public function testEmailConfirmationTokenCleanupDeletesOnlyStale(): void
    {
        $repo = new DoctrineEmailConfirmationTokenRepository($this->entityManager, $this->entityManager->getConnection());
        $now = new \DateTimeImmutable();

        $expired = EmailConfirmationToken::issue(bin2hex(random_bytes(16)), 'raw-expired', $now->modify('-30 days'));
        $consumedOld = EmailConfirmationToken::issue(bin2hex(random_bytes(16)), 'raw-cold', $now->modify('-30 days'));
        $consumedOld->markConfirmed($now->modify('-20 days'));
        $consumedRecent = EmailConfirmationToken::issue(bin2hex(random_bytes(16)), 'raw-crecent', $now);
        $consumedRecent->markConfirmed($now->modify('-1 hour'));
        $active = EmailConfirmationToken::issue(bin2hex(random_bytes(16)), 'raw-active', $now);

        foreach ([$expired, $consumedOld, $consumedRecent, $active] as $token) {
            $this->entityManager->persist($token);
        }
        $this->entityManager->flush();

        $deleted = $repo->deleteStale($now, $now->modify('-7 days'));

        self::assertSame(2, $deleted);

        $this->entityManager->clear();
        $remaining = array_map(
            static fn (EmailConfirmationToken $t): string => $t->getId(),
            $this->entityManager->getRepository(EmailConfirmationToken::class)->findAll(),
        );
        self::assertCount(2, $remaining);
        self::assertContains($consumedRecent->getId(), $remaining);
        self::assertContains($active->getId(), $remaining);
    }

    public function testPasswordResetTokenCleanupDeletesOnlyStale(): void
    {
        $repo = new DoctrinePasswordResetTokenRepository($this->entityManager, $this->entityManager->getConnection());
        $now = new \DateTimeImmutable();

        $expired = PasswordResetToken::issue(bin2hex(random_bytes(16)), 'raw-expired', $now->modify('-1 day'));
        $usedOld = PasswordResetToken::issue(bin2hex(random_bytes(16)), 'raw-uold', $now->modify('-1 day'));
        $usedOld->markUsed($now->modify('-1 day'));
        $usedRecent = PasswordResetToken::issue(bin2hex(random_bytes(16)), 'raw-urecent', $now);
        $usedRecent->markUsed($now->modify('-1 minute'));
        $active = PasswordResetToken::issue(bin2hex(random_bytes(16)), 'raw-active', $now);

        foreach ([$expired, $usedOld, $usedRecent, $active] as $token) {
            $this->entityManager->persist($token);
        }
        $this->entityManager->flush();

        $deleted = $repo->deleteStale($now, $now->modify('-7 days'));

        self::assertSame(2, $deleted);

        $this->entityManager->clear();
        $remaining = array_map(
            static fn (PasswordResetToken $t): string => $t->getId(),
            $this->entityManager->getRepository(PasswordResetToken::class)->findAll(),
        );
        self::assertCount(2, $remaining);
        self::assertContains($usedRecent->getId(), $remaining);
        self::assertContains($active->getId(), $remaining);
    }

    public function testHelloAssoSyncLogCleanupDeletesOnlyOld(): void
    {
        $repo = new DoctrineHelloAssoSyncLogRepository($this->entityManager, $this->entityManager->getConnection());
        $now = new \DateTimeImmutable();

        $old = HelloAssoSyncLog::fromSuccess('membership-form', $now->modify('-100 days'));
        $recent = HelloAssoSyncLog::fromSuccess('membership-form', $now->modify('-1 day'));
        $repo->persist($old);
        $repo->persist($recent);
        $repo->flush();

        $deleted = $repo->deleteOlderThan($now->modify('-90 days'));

        self::assertSame(1, $deleted);

        $this->entityManager->clear();
        $remaining = $this->entityManager->getRepository(HelloAssoSyncLog::class)->findAll();
        self::assertCount(1, $remaining);
        self::assertSame($recent->getId(), $remaining[0]->getId());
    }

    public function testEventPrivateAccessLogCleanupDeletesOnlyOld(): void
    {
        $repo = new DoctrineEventPrivateAccessLogRepository($this->entityManager, $this->entityManager->getConnection());
        $now = new \DateTimeImmutable();

        $old = new EventPrivateAccessLog(bin2hex(random_bytes(16)), bin2hex(random_bytes(16)), bin2hex(random_bytes(16)), true, $now->modify('-400 days'));
        $recent = new EventPrivateAccessLog(bin2hex(random_bytes(16)), bin2hex(random_bytes(16)), bin2hex(random_bytes(16)), true, $now->modify('-10 days'));
        $repo->save($old);
        $repo->save($recent);

        $deleted = $repo->deleteOlderThan($now->modify('-365 days'));

        self::assertSame(1, $deleted);

        $this->entityManager->clear();
        $remaining = $this->entityManager->getRepository(EventPrivateAccessLog::class)->findAll();
        self::assertCount(1, $remaining);
        self::assertSame($recent->getId(), $remaining[0]->getId());
    }
}
