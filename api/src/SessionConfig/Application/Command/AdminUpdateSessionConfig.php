<?php

declare(strict_types=1);

namespace App\SessionConfig\Application\Command;

use App\SessionConfig\Domain\Enum\SessionType;
use App\SessionConfig\Domain\Repository\SessionConfigProfileRepositoryInterface;
use App\SessionConfig\Domain\ValueObject\SessionConfig;

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
