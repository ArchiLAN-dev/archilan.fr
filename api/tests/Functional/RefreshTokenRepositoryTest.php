<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Application\RefreshTokenFactory;
use App\Identity\Application\RefreshTokenRepository;
use App\Identity\Application\RegisterUser;
use App\Identity\Domain\EmailConfirmationToken;
use App\Identity\Domain\RefreshToken;
use App\Identity\Domain\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RefreshTokenRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private RefreshTokenRepository $repository;
    private RefreshTokenFactory $factory;
    private string $userId;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $this->repository = new RefreshTokenRepository($em, $em->getConnection());

        $this->factory = new RefreshTokenFactory();

        $schemaTool = new SchemaTool($this->em);
        $schemaTool->dropSchema([
            $this->em->getClassMetadata(RefreshToken::class),
            $this->em->getClassMetadata(EmailConfirmationToken::class),
            $this->em->getClassMetadata(User::class),
        ]);
        $schemaTool->createSchema([
            $this->em->getClassMetadata(User::class),
            $this->em->getClassMetadata(RefreshToken::class),
            $this->em->getClassMetadata(EmailConfirmationToken::class),
        ]);

        $registerUser = self::getContainer()->get(RegisterUser::class);
        self::assertInstanceOf(RegisterUser::class, $registerUser);
        $result = $registerUser->register('test@example.org', 'correct horse battery staple', true, 'Jean');
        self::assertSame([], $result['errors']);
        $user = $result['user'] ?? null;
        self::assertInstanceOf(User::class, $user);
        $this->userId = $user->getId();
    }

    public function testFindByTokenHashReturnsCorrectEntity(): void
    {
        $now = new \DateTimeImmutable();
        ['rawToken' => $rawToken, 'entity' => $entity] = $this->factory->issue($this->userId, $now);
        $this->repository->save($entity);

        $found = $this->repository->findByTokenHash(hash('sha256', $rawToken));

        self::assertInstanceOf(RefreshToken::class, $found);
        self::assertSame($entity->getTokenHash(), $found->getTokenHash());
        self::assertSame($this->userId, $found->getUserId());
    }

    public function testFindByTokenHashReturnsNullForUnknownHash(): void
    {
        $result = $this->repository->findByTokenHash(str_repeat('a', 64));

        self::assertNull($result);
    }

    public function testRevokeAllForUserSetsRevokedAt(): void
    {
        $now = new \DateTimeImmutable();
        ['entity' => $token1] = $this->factory->issue($this->userId, $now);
        ['entity' => $token2] = $this->factory->issue($this->userId, $now);
        $this->repository->save($token1);
        $this->em->persist($token2);
        $this->em->flush();

        $this->repository->revokeAllForUser($this->userId);

        $this->em->clear();

        $refreshed1 = $this->repository->findByTokenHash($token1->getTokenHash());
        $refreshed2 = $this->repository->findByTokenHash($token2->getTokenHash());

        self::assertNotNull($refreshed1);
        self::assertTrue($refreshed1->isRevoked());
        self::assertNotNull($refreshed2);
        self::assertTrue($refreshed2->isRevoked());
    }

    public function testDeleteExpiredBeforeRemovesOnlyEligibleRows(): void
    {
        $pastDate = new \DateTimeImmutable('-2 days');
        $futureDate = new \DateTimeImmutable('+30 days');
        $threshold = new \DateTimeImmutable('now');

        $expiredToken = RefreshToken::issue($this->userId, 'raw-expired', $pastDate, $pastDate->modify('-31 days'));
        $activeToken = RefreshToken::issue($this->userId, 'raw-active', $futureDate, new \DateTimeImmutable());
        $this->em->persist($expiredToken);
        $this->em->persist($activeToken);
        $this->em->flush();

        $deleted = $this->repository->deleteExpiredBefore($threshold);

        self::assertSame(1, $deleted);
        self::assertNull($this->repository->findByTokenHash($expiredToken->getTokenHash()));
        self::assertNotNull($this->repository->findByTokenHash($activeToken->getTokenHash()));
    }
}
