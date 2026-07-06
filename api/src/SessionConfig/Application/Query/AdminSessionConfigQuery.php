<?php

declare(strict_types=1);

namespace App\SessionConfig\Application\Query;

use App\SessionConfig\Domain\Enum\SessionType;
use App\SessionConfig\Domain\Repository\SessionConfigProfileRepositoryInterface;

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
