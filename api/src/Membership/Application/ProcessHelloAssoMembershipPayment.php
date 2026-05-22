<?php

declare(strict_types=1);

namespace App\Membership\Application;

use App\Identity\Domain\User;
use App\Identity\Domain\UserRepositoryInterface;
use App\Membership\Application\Message\MembershipPaymentUnmatchedMessage;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class ProcessHelloAssoMembershipPayment implements ProcessHelloAssoMembershipPaymentInterface
{
    public function __construct(
        private UserRepositoryInterface $users,
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

        $user = $this->users->findByEmailCanonical(strtolower($payerEmail));

        if (!$user instanceof User || null !== $user->getDeletedAt()) {
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
            $this->activateMembership->activate($user->getId(), $paidAt, 'helloasso', $helloassoOrderId);
        } catch (UniqueConstraintViolationException) {
            $this->logger->info('membership.already_processed', [
                'helloassoOrderId' => $helloassoOrderId,
            ]);
        }
    }
}
