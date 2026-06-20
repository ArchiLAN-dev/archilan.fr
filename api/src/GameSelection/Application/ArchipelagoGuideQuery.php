<?php

declare(strict_types=1);

namespace App\GameSelection\Application;

use App\GameSelection\Domain\ArchipelagoGuideRepositoryInterface;

final readonly class ArchipelagoGuideQuery
{
    public function __construct(
        private ArchipelagoGuideRepositoryInterface $repository,
        private InstallStepsReader $installStepsReader,
    ) {
    }

    /**
     * @return list<array{type: string, title: string, description: string, links: list<array{label: string, url: string|null}>, imageKey: string|null, imageUrl: string|null, videoUrl: string|null}>
     */
    public function steps(): array
    {
        $guide = $this->repository->get();
        if (null === $guide) {
            return [];
        }

        // The reader drops unknown-type steps and resolves each image (presigning an uploaded imageKey),
        // mirroring the game-detail read.
        return $this->installStepsReader->present($guide->getSteps());
    }
}
