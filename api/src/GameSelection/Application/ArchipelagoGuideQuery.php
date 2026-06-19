<?php

declare(strict_types=1);

namespace App\GameSelection\Application;

use App\GameSelection\Domain\ArchipelagoGuideRepositoryInterface;
use App\GameSelection\Domain\InstallStepType;

final readonly class ArchipelagoGuideQuery
{
    public function __construct(
        private ArchipelagoGuideRepositoryInterface $repository,
    ) {
    }

    /**
     * @return list<array{type: string, title: string, description: string, links: list<array{label: string, url: string|null}>}>
     */
    public function steps(): array
    {
        $guide = $this->repository->get();
        if (null === $guide) {
            return [];
        }

        // Drop any step with an unknown type so a stale row never invalidates the whole guide
        // on the client (the public type guard is all-or-nothing) - mirrors the game-detail read.
        return array_values(array_filter(
            $guide->getSteps(),
            static fn (array $step): bool => InstallStepType::isValid($step['type']),
        ));
    }
}
