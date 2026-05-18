<?php

declare(strict_types=1);

namespace App\Membership\Application\Handler;

use App\Communications\Application\ArchilanMailer;
use App\Communications\Application\Email\MembershipPaymentUnmatchedEmail;
use App\Membership\Application\Message\MembershipPaymentUnmatchedMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class MembershipPaymentUnmatchedMessageHandler
{
    public function __construct(
        private ArchilanMailer $mailer,
        private string $discordInviteUrl,
        private string $siteUrl,
    ) {
    }

    public function __invoke(MembershipPaymentUnmatchedMessage $message): void
    {
        $this->mailer->send(new MembershipPaymentUnmatchedEmail(
            $message->payerEmail,
            $message->payerFirstName,
            $message->helloassoOrderId,
            $this->discordInviteUrl,
            $this->siteUrl,
        ));
    }
}
