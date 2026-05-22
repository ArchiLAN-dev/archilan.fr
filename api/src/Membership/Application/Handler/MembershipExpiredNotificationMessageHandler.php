<?php

declare(strict_types=1);

namespace App\Membership\Application\Handler;

use App\Communications\Application\Email\MembershipExpiredEmail;
use App\Identity\Domain\User;
use App\Identity\Domain\UserRepositoryInterface;
use App\Membership\Application\Message\MembershipExpiredNotificationMessage;
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

    public function __invoke(MembershipExpiredNotificationMessage $message): void
    {
        $user = $this->users->findById($message->userId);

        if (!$user instanceof User || null !== $user->getDeletedAt()) {
            $this->logger->error('membership.expired_notification_user_not_found', [
                'userId' => $message->userId,
            ]);

            throw new \RuntimeException('Membership expired notification user not found.');
        }

        $recipientEmail = $user->getEmail();
        $displayName = $user->getDisplayName();

        if ('' === $recipientEmail) {
            $this->logger->error('membership.expired_notification_invalid_email', [
                'userId' => $message->userId,
            ]);

            return;
        }

        $renewalUrl = $this->buildRenewalUrl();

        $emailObj = new MembershipExpiredEmail(
            $recipientEmail,
            $displayName,
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
