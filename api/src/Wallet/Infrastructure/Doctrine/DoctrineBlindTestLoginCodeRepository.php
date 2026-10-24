<?php

declare(strict_types=1);

namespace App\Wallet\Infrastructure\Doctrine;

use App\Wallet\Domain\Entity\BlindTestLoginCode;
use App\Wallet\Domain\Repository\BlindTestLoginCodeRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineBlindTestLoginCodeRepository implements BlindTestLoginCodeRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function find(string $codeHash): ?BlindTestLoginCode
    {
        return $this->entityManager->find(BlindTestLoginCode::class, $codeHash);
    }

    public function save(BlindTestLoginCode $code): void
    {
        $this->entityManager->persist($code);
        $this->entityManager->flush();
    }

    public function remove(BlindTestLoginCode $code): void
    {
        $this->entityManager->remove($code);
        $this->entityManager->flush();
    }

    public function removeExpired(\DateTimeImmutable $now): void
    {
        $this->entityManager->getConnection()->createQueryBuilder()
            ->delete('blind_test_login_code')
            ->where('expires_at <= :now')
            ->setParameter('now', $now->format('Y-m-d H:i:sP'))
            ->executeStatement();
    }
}
