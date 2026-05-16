<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Communications\Application\EmailConfirmationMessage;
use App\Identity\Domain\EmailConfirmationToken;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class SendEmailConfirmation
{
    private string $table;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private Connection $connection,
        private MessageBusInterface $messageBus,
    ) {
        $this->table = $entityManager->getClassMetadata(EmailConfirmationToken::class)->getTableName();
    }

    public function sendFor(string $userId, string $userEmail, ?string $userDisplayName, \DateTimeImmutable $now): void
    {
        $this->revokeExistingTokens($userId, $now);

        $rawToken = bin2hex(random_bytes(32));
        $token = EmailConfirmationToken::issue($userId, $rawToken, $now);
        $this->entityManager->persist($token);
        $this->entityManager->flush();

        $this->messageBus->dispatch(new EmailConfirmationMessage(
            userEmail: $userEmail,
            userDisplayName: $userDisplayName,
            rawToken: $rawToken,
            expiresAt: $token->getExpiresAt()->format(\DateTimeInterface::ATOM),
        ));
    }

    private function revokeExistingTokens(string $userId, \DateTimeImmutable $now): void
    {
        $qb = $this->connection->createQueryBuilder();
        $qb->update($this->table)
            ->set('confirmed_at', ':now')
            ->where($qb->expr()->eq('user_id', ':userId'))
            ->andWhere($qb->expr()->isNull('confirmed_at'))
            ->setParameter('now', $now, Types::DATETIMETZ_IMMUTABLE)
            ->setParameter('userId', $userId)
            ->executeStatement();
    }
}
