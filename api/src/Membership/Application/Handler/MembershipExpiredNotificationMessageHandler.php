<?php

declare(strict_types=1);

namespace App\Membership\Application\Handler;

use App\Communications\Application\Email\MembershipExpiredEmail;
use App\Membership\Application\Message\MembershipExpiredNotificationMessage;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

#[AsMessageHandler]
final readonly class MembershipExpiredNotificationMessageHandler
{
    public function __construct(
        private Connection $connection,
        private MailerInterface $mailer,
        private LoggerInterface $logger,
        private string $mailerSender,
        private string $siteUrl,
        #[Autowire('%env(HELLOASSO_ORGANIZATION_SLUG)%')]
        private string $organizationSlug,
        #[Autowire('%env(HELLOASSO_MEMBERSHIP_FORM_SLUG)%')]
        private string $membershipFormSlug,
        #[Autowire('%env(bool:HELLOASSO_SANDBOX)%')]
        private bool $sandbox,
    ) {
    }

    public function __invoke(MembershipExpiredNotificationMessage $message): void
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
            $this->logger->error('membership.expired_notification_user_not_found', [
                'userId' => $message->userId,
            ]);

            throw new \RuntimeException('Membership expired notification user not found.');
        }

        $recipientEmail = $row['email'];
        $displayName = $row['display_name'];

        if (!is_string($recipientEmail) || '' === $recipientEmail) {
            $this->logger->error('membership.expired_notification_invalid_email', [
                'userId' => $message->userId,
            ]);

            return;
        }

        $displayNameStr = is_string($displayName) ? $displayName : null;

        $renewalUrl = $this->buildRenewalUrl();

        $emailObj = new MembershipExpiredEmail(
            $recipientEmail,
            $displayNameStr,
            $renewalUrl,
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
            $this->logger->error('membership.expired_notification_send_failed', [
                'userId' => $message->userId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function buildRenewalUrl(): string
    {
        if ('' === $this->organizationSlug || '' === $this->membershipFormSlug) {
            return rtrim($this->siteUrl, '/').'/adhesion';
        }

        $baseHost = $this->sandbox ? 'www.helloasso-sandbox.com' : 'www.helloasso.com';

        return sprintf(
            'https://%s/associations/%s/adhesions/%s',
            $baseHost,
            $this->organizationSlug,
            $this->membershipFormSlug,
        );
    }
}
