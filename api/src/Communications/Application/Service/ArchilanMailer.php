<?php

declare(strict_types=1);

namespace App\Communications\Application\Service;

use App\Communications\Application\Email\ArchilanEmail;
use App\Shared\Application\Support\EmailMask;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

final readonly class ArchilanMailer
{
    /**
     * Seconds before each new attempt after a transient SMTP refusal (#1229): the relay answered
     * "421 Service not available" once and the mail of the day before was lost for a member.
     */
    private const array TRANSIENT_RETRY_DELAYS = [2, 5];

    public function __construct(
        private MailerInterface $mailer,
        private LoggerInterface $logger,
        private string $mailerSender,
        private ClockInterface $clock,
    ) {
    }

    public function send(ArchilanEmail $email): bool
    {
        $toName = $email->toName() ?? $email->to();

        $message = new Email()
            ->from(new Address($this->mailerSender, 'ArchiLAN'))
            ->to(new Address($email->to(), $toName))
            ->subject($email->subject())
            ->text($email->textBody());

        if (null !== $email->htmlBody()) {
            $message->html($email->htmlBody());
        }

        $attempt = 0;
        while (true) {
            try {
                $this->mailer->send($message);

                return true;
            } catch (\Throwable $e) {
                $delay = self::TRANSIENT_RETRY_DELAYS[$attempt] ?? null;
                if (null !== $delay && self::isTransient($e)) {
                    $this->logger->warning('archilan_mailer.send_retry', [
                        'recipient' => EmailMask::mask($email->to()),
                        'subject' => $email->subject(),
                        'attempt' => $attempt + 1,
                        'error' => $e->getMessage(),
                    ]);
                    $this->clock->sleep($delay);
                    ++$attempt;
                    continue;
                }

                $this->logger->error('archilan_mailer.send_failed', [
                    'recipient' => EmailMask::mask($email->to()),
                    'subject' => $email->subject(),
                    'error' => $e->getMessage(),
                ]);

                return false;
            }
        }
    }

    /** An SMTP 4xx reply is a temporary refusal: worth another attempt. A 5xx or anything else is final. */
    private static function isTransient(\Throwable $e): bool
    {
        return $e instanceof UnexpectedResponseException && $e->getCode() >= 400 && $e->getCode() < 500;
    }
}
