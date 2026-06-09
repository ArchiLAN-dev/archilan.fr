<?php

declare(strict_types=1);

namespace App\SessionConfig\Application;

use App\SessionConfig\Domain\SessionConfig;
use App\SessionConfig\Domain\SessionConfigProfileRepositoryInterface;
use App\SessionConfig\Domain\SessionType;

final readonly class AdminUpdateSessionConfig
{
    public function __construct(
        private SessionConfigProfileRepositoryInterface $profiles,
    ) {
    }

    /**
     * Validates the payload through the domain value objects (throws \DomainException on
     * any invalid field) and persists it. Returns the saved profile's canonical array.
     *
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    public function execute(SessionType $type, array $config): array
    {
        $sessionConfig = SessionConfig::fromArray($config);
        $this->profiles->save($type, $sessionConfig);

        return [
            'type' => $type->value,
            'config' => $sessionConfig->toArray(),
        ];
    }
}
