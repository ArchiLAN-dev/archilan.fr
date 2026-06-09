<?php

declare(strict_types=1);

namespace App\SessionConfig\Infrastructure;

use App\SessionConfig\Domain\SessionConfig;
use App\SessionConfig\Domain\SessionConfigProfile;
use App\SessionConfig\Domain\SessionConfigProfileRepositoryInterface;
use App\SessionConfig\Domain\SessionType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

final readonly class DoctrineSessionConfigProfileRepository implements SessionConfigProfileRepositoryInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function get(SessionType $type): SessionConfig
    {
        $profile = $this->entityManager->find(SessionConfigProfile::class, $type->value);

        // No row yet → the domain default is the source of truth (no seed migration needed).
        return $profile instanceof SessionConfigProfile
            ? $profile->toSessionConfig()
            : SessionConfig::defaultsFor($type);
    }

    public function save(SessionType $type, SessionConfig $config): void
    {
        $now = $this->clock->now();
        $profile = $this->entityManager->find(SessionConfigProfile::class, $type->value);

        if ($profile instanceof SessionConfigProfile) {
            $profile->update($config, $now);
        } else {
            $this->entityManager->persist(new SessionConfigProfile($type->value, $config, $now));
        }

        $this->entityManager->flush();
    }
}
