<?php

declare(strict_types=1);

namespace App\Communications\Application;

use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

final readonly class ArchilanMailer
{
    public function __construct(
        private MailerInterface $mailer,
        private LoggerInterface $logger,
        private string $mailerSender,
    ) {
    }

    public function send(ArchilanEmail $email): bool
    {
        $toName = $email->toName() ?? $email->to();

        $message = (new Email())
            ->from(new Address($this->mailerSender, 'ArchiLAN'))
            ->to(new Address($email->to(), $toName))
            ->subject($email->subject())
            ->text($email->textBody());

        if (null !== $email->htmlBody()) {
            $message->html($email->htmlBody());
        }

        try {
            $this->mailer->send($message);

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('archilan_mailer.send_failed', [
                'recipient' => $email->to(),
                'subject' => $email->subject(),
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
