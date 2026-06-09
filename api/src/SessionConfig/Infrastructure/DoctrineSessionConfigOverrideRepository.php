<?php

declare(strict_types=1);

namespace App\SessionConfig\Infrastructure;

use App\SessionConfig\Domain\SessionConfigOverride;
use App\SessionConfig\Domain\SessionConfigOverrideRepositoryInterface;
use App\SessionConfig\Domain\SessionConfigOverrideStore;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

final readonly class DoctrineSessionConfigOverrideRepository implements SessionConfigOverrideRepositoryInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function find(string $scopeKey): ?SessionConfigOverride
    {
        $store = $this->entityManager->find(SessionConfigOverrideStore::class, $scopeKey);

        return $store instanceof SessionConfigOverrideStore ? $store->toOverride() : null;
    }

    public function save(string $scopeKey, SessionConfigOverride $override): void
    {
        $now = $this->clock->now();
        $store = $this->entityManager->find(SessionConfigOverrideStore::class, $scopeKey);

        if ($store instanceof SessionConfigOverrideStore) {
            $store->update($override, $now);
        } else {
            $this->entityManager->persist(new SessionConfigOverrideStore($scopeKey, $override, $now));
        }

        $this->entityManager->flush();
    }

    public function delete(string $scopeKey): void
    {
        $store = $this->entityManager->find(SessionConfigOverrideStore::class, $scopeKey);
        if ($store instanceof SessionConfigOverrideStore) {
            $this->entityManager->remove($store);
            $this->entityManager->flush();
        }
    }
}
