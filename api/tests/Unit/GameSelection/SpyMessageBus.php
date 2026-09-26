<?php

declare(strict_types=1);

namespace App\Tests\Unit\GameSelection;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Records dispatched messages together with how many times the incident store had flushed at that
 * moment, so a test can prove an alert only leaves after the commit (story 38.2 tests).
 */
final class SpyMessageBus implements MessageBusInterface
{
    /** @var list<array{message: object, flushesBefore: int, stamps: list<StampInterface>}> */
    public array $dispatched = [];

    public function __construct(private readonly InMemoryApworldIncidentRepository $incidents)
    {
    }

    public function dispatch(object $message, array $stamps = []): Envelope
    {
        $this->dispatched[] = ['message' => $message, 'flushesBefore' => $this->incidents->flushes, 'stamps' => array_values($stamps)];

        return new Envelope($message);
    }

    /**
     * @return list<object>
     */
    public function messages(): array
    {
        return array_column($this->dispatched, 'message');
    }
}
