<?php

declare(strict_types=1);

namespace App\Shared\Application\Exception;

/**
 * Shared implementation of {@see ApplicationFailure} for the concrete final exceptions. A trait rather than
 * an abstract base because Application classes must be final (api/CLAUDE.md AC-A1); traits pass the DDD
 * validator untouched. Each using exception extends \RuntimeException, sets the code/message/details in its
 * constructor, and defines its own statusCode().
 */
trait ApplicationFailureTrait
{
    private string $failureCode = '';

    private string $failureMessage = '';

    /** @var array<string, mixed> */
    private array $failureDetails = [];

    public function errorCode(): string
    {
        return $this->failureCode;
    }

    public function clientMessage(): string
    {
        return $this->failureMessage;
    }

    /**
     * @return array<string, mixed>
     */
    public function details(): array
    {
        return $this->failureDetails;
    }
}
