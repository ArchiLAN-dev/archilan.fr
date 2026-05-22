<?php

declare(strict_types=1);

namespace App\Membership\Application\Handler;

use App\Communications\Application\Email\MembershipReminderEmail;
use App\Identity\Domain\User;
use App\Identity\Domain\UserRepositoryInterface;
use App\Membership\Application\Message\MembershipReminderMessage;
use App\Membership\Domain\Membership;
use App\Membership\Domain\MembershipRepositoryInterface;
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
        private MembershipRepositoryInterface $memberships,
        private UserRepositoryInterface $users,
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
        $membership = $this->memberships->findById($message->membershipId);

        if (!$membership instanceof Membership) {
            $this->logger->error('membership.reminder_notification_not_found', [
                'membershipId' => $message->membershipId,
                'daysLeft' => $message->daysLeft,
            ]);

            throw new \RuntimeException('Membership reminder notification target not found.');
        }

        $user = $this->users->findById($membership->getUserId());

        if (!$user instanceof User || null !== $user->getDeletedAt()) {
            $this->logger->error('membership.reminder_notification_not_found', [
                'membershipId' => $message->membershipId,
                'daysLeft' => $message->daysLeft,
            ]);

            throw new \RuntimeException('Membership reminder notification target not found.');
        }

        $recipientEmail = $user->getEmail();
        $displayName = $user->getDisplayName();

        if ('' === $recipientEmail) {
            $this->logger->error('membership.reminder_notification_invalid_email', [
                'membershipId' => $message->membershipId,
            ]);

            return;
        }

        $expiryFormatted = $membership->getExpiresAt()->format('d/m/Y');
        $renewalUrl = $this->buildRenewalUrl();

        $emailObj = new MembershipReminderEmail(
            $recipientEmail,
            $displayName,
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
