<?php

declare(strict_types=1);

namespace App\Identity\Application;

final class ValidationErrors
{
    /**
     * @var array<string, list<string>>
     */
    private array $errors = [];

    public function add(string $field, string $message): void
    {
        $this->errors[$field][] = $message;
    }

    /**
     * @return array<string, list<string>>
     */
    public function toArray(): array
    {
        return $this->errors;
    }
}
