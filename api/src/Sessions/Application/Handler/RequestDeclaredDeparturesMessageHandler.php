<?php

declare(strict_types=1);

namespace App\Sessions\Application\Handler;

use App\Sessions\Application\Command\RequestDeclaredDepartures;
use App\Sessions\Application\Message\RequestDeclaredDeparturesMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class RequestDeclaredDeparturesMessageHandler
{
    public function __construct(
        private RequestDeclaredDepartures $departures,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(RequestDeclaredDeparturesMessage $message): void
    {
        $opened = $this->departures->execute();
        if ($opened > 0) {
            $this->logger->info('sessions.departure.declared', ['requests' => $opened]);
        }
    }
}
