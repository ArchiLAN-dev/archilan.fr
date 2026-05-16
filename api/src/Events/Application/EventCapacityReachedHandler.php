<?php

declare(strict_types=1);

namespace App\Events\Application;

use App\Communications\Application\ArchilanMailer;
use App\Communications\Application\Email\EventCapacityReachedEmail;
use App\Identity\Domain\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class EventCapacityReachedHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ArchilanMailer $mailer,
        private LoggerInterface $logger,
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
        $users = $this->entityManager->getRepository(User::class)->findBy(['deletedAt' => null]);

        foreach ($users as $user) {
            if (!in_array('ROLE_ADMIN', $user->getRoles(), true)) {
                continue;
            }

            $this->mailer->send(new EventCapacityReachedEmail(
                $user->getEmail(),
                $user->getDisplayName(),
                $message->eventTitle,
                $message->capacity,
            ));
        }
    }
}
