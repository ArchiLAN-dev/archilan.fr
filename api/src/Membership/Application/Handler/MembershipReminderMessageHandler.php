<?php

declare(strict_types=1);

namespace App\Membership\Application\Handler;

use App\Communications\Application\Email\MembershipReminderEmail;
use App\Membership\Application\Message\MembershipReminderMessage;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

#[AsMessageHandler]
final readonly class MembershipReminderMessageHandler
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

    public function __invoke(MembershipReminderMessage $message): void
    {
        $userTable = $this->connection->quoteSingleIdentifier('user');
        $qb = $this->connection->createQueryBuilder();
        $row = $qb
            ->select('u.email', 'u.display_name', 'm.expires_at')
            ->from('memberships', 'm')
            ->innerJoin('m', $userTable, 'u', $qb->expr()->eq('u.id', 'm.user_id'))
            ->where($qb->expr()->eq('m.id', ':membershipId'))
            ->andWhere($qb->expr()->isNull('u.deleted_at'))
            ->setParameter('membershipId', $message->membershipId)
            ->executeQuery()
            ->fetchAssociative();

        if (false === $row) {
            $this->logger->error('membership.reminder_notification_not_found', [
                'membershipId' => $message->membershipId,
                'daysLeft' => $message->daysLeft,
            ]);

            throw new \RuntimeException('Membership reminder notification target not found.');
        }

        $recipientEmail = $row['email'];
        $displayName = $row['display_name'];
        $expiresAtRaw = $row['expires_at'];

        if (!is_string($recipientEmail) || '' === $recipientEmail) {
            $this->logger->error('membership.reminder_notification_invalid_email', [
                'membershipId' => $message->membershipId,
            ]);

            return;
        }

        $displayNameStr = is_string($displayName) ? $displayName : null;

        $expiresAt = is_string($expiresAtRaw)
            ? new \DateTimeImmutable($expiresAtRaw)
            : new \DateTimeImmutable();

        $expiryFormatted = $expiresAt->format('d/m/Y');
        $renewalUrl = $this->buildRenewalUrl();

        $emailObj = new MembershipReminderEmail(
            $recipientEmail,
            $displayNameStr,
            $message->daysLeft,
            $expiryFormatted,
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
            $this->logger->error('membership.reminder_notification_send_failed', [
                'membershipId' => $message->membershipId,
                'daysLeft' => $message->daysLeft,
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
