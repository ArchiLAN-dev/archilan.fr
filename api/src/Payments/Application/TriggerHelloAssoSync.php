<?php

declare(strict_types=1);

namespace App\Payments\Application;

use App\Events\Domain\EventRepositoryInterface;
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
     * @return array{found: bool, hasFormSlug: bool, dispatched: bool, configurationError: string|null}
     */
    public function triggerForEvent(string $eventId): array
    {
        $event = $this->eventRepository->findById($eventId);
        if (null === $event) {
            return ['found' => false, 'hasFormSlug' => false, 'dispatched' => false, 'configurationError' => null];
        }

        $formSlug = $event->getHelloassoFormSlug();

        if (null === $formSlug) {
            return ['found' => true, 'hasFormSlug' => false, 'dispatched' => false, 'configurationError' => null];
        }

        try {
            $this->config->assertApiAccessConfigured();
        } catch (\RuntimeException $e) {
            $this->logger->warning('helloasso.sync.config_error', ['eventId' => $eventId, 'error' => $e->getMessage()]);

            return ['found' => true, 'hasFormSlug' => true, 'dispatched' => false, 'configurationError' => $e->getMessage()];
        }

        $this->bus->dispatch(new SyncHelloAssoFormMessage(HelloAssoConfig::FORM_TYPE_EVENT, $formSlug));

        $this->logger->info('helloasso.sync.triggered', ['eventId' => $eventId, 'formSlug' => $formSlug]);

        return ['found' => true, 'hasFormSlug' => true, 'dispatched' => true, 'configurationError' => null];
    }
}
