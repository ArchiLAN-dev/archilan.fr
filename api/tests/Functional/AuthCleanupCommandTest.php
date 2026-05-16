<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Application\RefreshTokenRepository;
use App\Identity\Application\RegisterLambdaUser;
use App\Identity\Domain\EmailConfirmationToken;
use App\Identity\Domain\RefreshToken;
use App\Identity\Domain\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class AuthCleanupCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private RefreshTokenRepository $repository;
    private string $userId;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $this->repository = new RefreshTokenRepository($em, $em->getConnection());

        $metadata = [
            $this->em->getClassMetadata(User::class),
            $this->em->getClassMetadata(RefreshToken::class),
            $this->em->getClassMetadata(EmailConfirmationToken::class),
        ];
        $schemaTool = new SchemaTool($this->em);
        $schemaTool->dropSchema(array_reverse($metadata));
        $schemaTool->createSchema($metadata);

        $register = self::getContainer()->get(RegisterLambdaUser::class);
        self::assertInstanceOf(RegisterLambdaUser::class, $register);
        $result = $register->register('user@example.org', 'correct horse battery staple', true);
        $user = $result['user'] ?? null;
        self::assertInstanceOf(User::class, $user);
        $this->userId = $user->getId();
    }

    public function testCommandDeletesExpiredAndOldRevokedTokensOnly(): void
    {
        $now = new \DateTimeImmutable();

        // Expired token (should be deleted)
        $expiredToken = RefreshToken::issue(
            $this->userId,
            'raw-expired',
            $now->modify('-1 day'),
            $now->modify('-32 days'),
        );

        // Revoked token older than 7 days (should be deleted)
        $oldRevokedToken = RefreshToken::issue(
            $this->userId,
            'raw-old-revoked',
            $now->modify('+30 days'),
            $now->modify('-10 days'),
        );
        $oldRevokedToken->revoke($now->modify('-8 days'));

        // Recently revoked token - revoked 3 days ago (should NOT be deleted)
        $recentlyRevokedToken = RefreshToken::issue(
            $this->userId,
            'raw-recent-revoked',
            $now->modify('+30 days'),
            $now->modify('-5 days'),
        );
        $recentlyRevokedToken->revoke($now->modify('-3 days'));

        // Active token - not expired, not revoked (should NOT be deleted)
        $activeToken = RefreshToken::issue(
            $this->userId,
            'raw-active',
            $now->modify('+30 days'),
            $now,
        );

        $this->em->persist($expiredToken);
        $this->em->persist($oldRevokedToken);
        $this->em->persist($recentlyRevokedToken);
        $this->em->persist($activeToken);
        $this->em->flush();

        $tester = $this->commandTester();
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Deleted 2 stale refresh token(s).', $tester->getDisplay());

        $this->em->clear();
        self::assertNull($this->repository->findByTokenHash(hash('sha256', 'raw-expired')));
        self::assertNull($this->repository->findByTokenHash(hash('sha256', 'raw-old-revoked')));
        self::assertNotNull($this->repository->findByTokenHash(hash('sha256', 'raw-recent-revoked')));
        self::assertNotNull($this->repository->findByTokenHash(hash('sha256', 'raw-active')));
    }

    public function testCommandWithNoStaleTokensOutputsZero(): void
    {
        $tester = $this->commandTester();
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Deleted 0 stale refresh token(s).', $tester->getDisplay());
    }

    private function commandTester(): CommandTester
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $app = new Application($kernel);
        $command = $app->find('app:auth:cleanup-refresh-tokens');

        return new CommandTester($command);
    }
}
