<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Communications\Application\PasswordResetMessage;
use App\Identity\Domain\PasswordResetToken;
use App\Identity\Domain\User;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class RequestPasswordReset
{
    private string $table;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private Connection $connection,
        private MessageBusInterface $messageBus,
    ) {
        $this->table = $entityManager->getClassMetadata(PasswordResetToken::class)->getTableName();
    }

    public function request(string $email, \DateTimeImmutable $now): void
    {
        $canonical = mb_strtolower(trim($email));
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['emailCanonical' => $canonical]);

        if (!$user instanceof User || $user->isDeleted()) {
            return;
        }

        $this->revokeExistingTokens($user->getId(), $now);

        $rawToken = bin2hex(random_bytes(32));
        $token = PasswordResetToken::issue($user->getId(), $rawToken, $now);
        $this->entityManager->persist($token);
        $this->entityManager->flush();

        $this->messageBus->dispatch(new PasswordResetMessage(
            userEmail: $user->getEmail(),
            userDisplayName: $user->getDisplayName(),
            rawToken: $rawToken,
            expiresAt: $token->getExpiresAt()->format(\DateTimeInterface::ATOM),
        ));
    }

    private function revokeExistingTokens(string $userId, \DateTimeImmutable $now): void
    {
        $qb = $this->connection->createQueryBuilder();
        $qb->update($this->table)
            ->set('used_at', ':now')
            ->where($qb->expr()->eq('user_id', ':userId'))
            ->andWhere($qb->expr()->isNull('used_at'))
            ->setParameter('now', $now, Types::DATETIMETZ_IMMUTABLE)
            ->setParameter('userId', $userId)
            ->executeStatement();
    }
}
