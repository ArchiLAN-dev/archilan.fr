<?php

declare(strict_types=1);

namespace App\Membership\Application\Handler;

use App\Communications\Application\Email\MembershipActivatedEmail;
use App\Identity\Domain\User;
use App\Identity\Domain\UserRepositoryInterface;
use App\Membership\Application\Message\MembershipActivatedNotificationMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

#[AsMessageHandler]
final readonly class MembershipActivatedNotificationMessageHandler
{
    public function __construct(
        private UserRepositoryInterface $users,
        private MailerInterface $mailer,
        private LoggerInterface $logger,
        private string $mailerSender,
        private string $siteUrl,
    ) {
    }

    public function __invoke(MembershipActivatedNotificationMessage $message): void
    {
        $user = $this->users->findById($message->userId);

        if (!$user instanceof User || null !== $user->getDeletedAt()) {
            $this->logger->error('membership.activated_notification_user_not_found', [
                'userId' => $message->userId,
            ]);

            throw new \RuntimeException('Membership activation notification user not found.');
        }

        $recipientEmail = $user->getEmail();
        $displayName = $user->getDisplayName();

        if ('' === $recipientEmail) {
            $this->logger->error('membership.activated_notification_invalid_email', [
                'userId' => $message->userId,
            ]);

            return;
        }

        $expiryFormatted = $message->expiresAt->format('d/m/Y');
        $profileUrl = rtrim($this->siteUrl, '/').'/compte';

        $emailObj = new MembershipActivatedEmail(
            $recipientEmail,
            $displayName,
            $expiryFormatted,
            $profileUrl,
            $this->siteUrl,
        );

        $mimeEmail = (new Email())
            ->from(new Address($this->mailerSender, 'ArchiLAN'))
            ->to(new Address($emailObj->to(), $emailObj->toName() ?? $emailObj->to()))
            ->subject($emailObj->subject())
            ->text($emailObj->textBody())
            ->html($emailObj->htmlBody());

        try {
            $this->mailer->send($mimeEmail);
        } catch (\Throwable $e) {
            $this->logger->error('membership.activated_notification_send_failed', [
                'userId' => $message->userId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
