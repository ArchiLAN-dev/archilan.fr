<?php

declare(strict_types=1);

namespace App\Events\Application;

use App\Identity\Domain\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

#[AsMessageHandler]
final readonly class EventCapacityReachedHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MailerInterface $mailer,
        private LoggerInterface $logger,
        private string $mailerSender,
    ) {
    }

    public function __invoke(EventCapacityReachedMessage $message): void
    {
        $this->logger->warning('admin.capacity_reached', [
            'eventId' => $message->eventId,
            'eventTitle' => $message->eventTitle,
            'capacity' => $message->capacity,
        ]);

        /** @var list<User> $users */
        $users = $this->entityManager->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->where('u.deletedAt IS NULL')
            ->getQuery()
            ->getResult();

        foreach ($users as $user) {
            if (!in_array('ROLE_ADMIN', $user->getRoles(), true)) {
                continue;
            }

            $body = <<<TEXT
Bonjour,

L'événement "{$message->eventTitle}" a atteint sa capacité maximale ({$message->capacity} places).

Connecte-toi au backoffice pour gérer les inscriptions.

L'équipe ArchiLAN
TEXT;

            $email = (new Email())
                ->from(new Address($this->mailerSender, 'ArchiLAN'))
                ->to(new Address($user->getEmail(), $user->getDisplayName() ?? $user->getEmail()))
                ->subject(sprintf('[ArchiLAN] Événement complet : %s', $message->eventTitle))
                ->text($body);

            try {
                $this->mailer->send($email);
            } catch (TransportExceptionInterface $e) {
                $this->logger->error('admin.capacity_notification_email_failed', [
                    'adminEmail' => $user->getEmail(),
                    'eventId' => $message->eventId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
