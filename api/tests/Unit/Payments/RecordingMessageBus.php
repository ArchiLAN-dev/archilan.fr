<?php

declare(strict_types=1);

namespace App\Tests\Unit\Payments;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Records every dispatched message (story 22.7 tests).
 */
final class RecordingMessageBus implements MessageBusInterface
{
    /** @var list<object> */
    public array $messages = [];

    public function dispatch(object $message, array $stamps = []): Envelope
    {
        $this->messages[] = $message;

        return new Envelope($message);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return list<T>
     */
    public function messagesOf(string $class): array
    {
        return array_values(array_filter($this->messages, static fn (object $m): bool => $m instanceof $class));
    }
}
