<?php

declare(strict_types=1);

namespace App\SessionConfig\Application;

use App\SessionConfig\Domain\SessionConfigProfileRepositoryInterface;
use App\SessionConfig\Domain\SessionType;

final readonly class AdminSessionConfigQuery
{
    public function __construct(
        private SessionConfigProfileRepositoryInterface $profiles,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(SessionType $type): array
    {
        return [
            'type' => $type->value,
            'config' => $this->profiles->get($type)->toArray(),
        ];
    }
}
