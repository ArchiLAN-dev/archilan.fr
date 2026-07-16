<?php

declare(strict_types=1);

namespace App\Payments\Application\Command;

use App\Events\Domain\Repository\EventRepositoryInterface;
use App\Payments\Application\Message\SyncHelloAssoFormMessage;
use App\Payments\Application\Support\HelloAssoConfig;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Exception\ServiceUnavailableException;
use App\Shared\Application\Exception\ValidationException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class TriggerHelloAssoSync
{
    public function __construct(
        private EventRepositoryInterface $eventRepository,
        private MessageBusInterface $bus,
        private HelloAssoConfig $config,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws NotFoundException           when the event does not exist
     * @throws ValidationException         when the event has no HelloAsso form configured
     * @throws ServiceUnavailableException when HelloAsso API access is not configured
     */
    public function triggerForEvent(string $eventId): void
    {
        $event = $this->eventRepository->findById($eventId);
        if (null === $event) {
            throw new NotFoundException('Événement introuvable.');
        }

        $formSlug = $event->getHelloassoFormSlug();

        if (null === $formSlug) {
            throw new ValidationException('Aucun formulaire HelloAsso configuré pour cet événement.', [], 'no_form_configured');
        }

        try {
            $this->config->assertApiAccessConfigured();
        } catch (\RuntimeException $e) {
            $this->logger->warning('helloasso.sync.config_error', ['eventId' => $eventId, 'error' => $e->getMessage()]);

            throw new ServiceUnavailableException($e->getMessage(), 'helloasso_not_configured');
        }

        $this->bus->dispatch(new SyncHelloAssoFormMessage(HelloAssoConfig::FORM_TYPE_EVENT, $formSlug));

        $this->logger->info('helloasso.sync.triggered', ['eventId' => $eventId, 'formSlug' => $formSlug]);
    }
}
