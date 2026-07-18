<?php

declare(strict_types=1);

namespace App\Identity\Application\Command;

final readonly class RegisteredUser
{
    /**
     * @param list<string> $roles
     */
    public function __construct(
        public string $id,
        public string $email,
        public array $roles,
    ) {
    }
}
