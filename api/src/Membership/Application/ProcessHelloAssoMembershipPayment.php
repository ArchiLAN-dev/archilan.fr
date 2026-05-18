<?php

declare(strict_types=1);

namespace App\Membership\Application;

use App\Membership\Application\Message\MembershipPaymentUnmatchedMessage;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class ProcessHelloAssoMembershipPayment implements ProcessHelloAssoMembershipPaymentInterface
{
    public function __construct(
        private Connection $connection,
        private ActivateMembershipInterface $activateMembership,
        private MessageBusInterface $bus,
        private LoggerInterface $logger,
    ) {
    }

    public function process(string $helloassoOrderId, ?string $payerEmail, ?\DateTimeImmutable $paidAt): void
    {
        if (null === $payerEmail) {
            $this->logger->info('membership.helloasso_payment_skipped_no_email', [
                'helloassoOrderId' => $helloassoOrderId,
            ]);

            return;
        }

        if (null === $paidAt) {
            $this->logger->warning('membership.helloasso_payment_skipped_no_paid_at', [
                'helloassoOrderId' => $helloassoOrderId,
            ]);

            return;
        }

        $userTable = $this->connection->quoteSingleIdentifier('user');
        $qb = $this->connection->createQueryBuilder();
        $result = $qb
            ->select('u.id')
            ->from($userTable, 'u')
            ->where($qb->expr()->eq('u.email_canonical', ':email'))
            ->andWhere($qb->expr()->isNull('u.deleted_at'))
            ->setParameter('email', strtolower($payerEmail))
            ->executeQuery()
            ->fetchOne();

        if (false === $result || !is_string($result)) {
            $this->logger->warning('membership.helloasso_payment_user_not_found', [
                'helloassoOrderId' => $helloassoOrderId,
                'payerEmail' => $payerEmail,
            ]);

            try {
                $this->bus->dispatch(new MembershipPaymentUnmatchedMessage($payerEmail, null, $helloassoOrderId));
            } catch (\Throwable $e) {
                $this->logger->error('membership.unmatched_notification_dispatch_failed', [
                    'helloassoOrderId' => $helloassoOrderId,
                    'error' => $e->getMessage(),
                ]);
            }

            return;
        }

        try {
            $this->activateMembership->activate($result, $paidAt, 'helloasso', $helloassoOrderId);
        } catch (UniqueConstraintViolationException) {
            $this->logger->info('membership.already_processed', [
                'helloassoOrderId' => $helloassoOrderId,
            ]);
        }
    }
}
