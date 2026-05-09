<?php

declare(strict_types=1);

namespace App\Shared\Application;

final readonly class DddArchitectureReport
{
    /**
     * @param list<string> $violations
     */
    public function __construct(private array $violations)
    {
    }

    public function isSuccessful(): bool
    {
        return [] === $this->violations;
    }

    /**
     * @return list<string>
     */
    public function violations(): array
    {
        return $this->violations;
    }
}
