<?php

declare(strict_types=1);

namespace App\Membership\Application\Handler;

use App\Communications\Application\Email\MembershipActivatedEmail;
use App\Membership\Application\Message\MembershipActivatedNotificationMessage;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

#[AsMessageHandler]
final readonly class MembershipActivatedNotificationMessageHandler
{
    public function __construct(
        private Connection $connection,
        private MailerInterface $mailer,
        private LoggerInterface $logger,
        private string $mailerSender,
        private string $siteUrl,
    ) {
    }

    public function __invoke(MembershipActivatedNotificationMessage $message): void
    {
        $userTable = $this->connection->quoteSingleIdentifier('user');
        $qb = $this->connection->createQueryBuilder();
        $row = $qb
            ->select('u.email', 'u.display_name')
            ->from($userTable, 'u')
            ->where($qb->expr()->eq('u.id', ':userId'))
            ->andWhere($qb->expr()->isNull('u.deleted_at'))
            ->setParameter('userId', $message->userId)
            ->executeQuery()
            ->fetchAssociative();

        if (false === $row) {
            $this->logger->error('membership.activated_notification_user_not_found', [
                'userId' => $message->userId,
            ]);

            throw new \RuntimeException('Membership activation notification user not found.');
        }

        $recipientEmail = $row['email'];
        $displayName = $row['display_name'];

        if (!is_string($recipientEmail) || '' === $recipientEmail) {
            $this->logger->error('membership.activated_notification_invalid_email', [
                'userId' => $message->userId,
            ]);

            return;
        }

        $displayNameStr = is_string($displayName) ? $displayName : null;

        $expiryFormatted = $message->expiresAt->format('d/m/Y');
        $profileUrl = rtrim($this->siteUrl, '/').'/compte';

        $emailObj = new MembershipActivatedEmail(
            $recipientEmail,
            $displayNameStr,
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
