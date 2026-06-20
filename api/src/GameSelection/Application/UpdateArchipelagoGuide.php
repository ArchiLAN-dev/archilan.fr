<?php

declare(strict_types=1);

namespace App\GameSelection\Application;

use App\GameSelection\Domain\ArchipelagoGuide;
use App\GameSelection\Domain\ArchipelagoGuideRepositoryInterface;

final readonly class UpdateArchipelagoGuide
{
    public function __construct(
        private ArchipelagoGuideRepositoryInterface $repository,
        private InstallStepsNormalizer $normalizer,
    ) {
    }

    /**
     * @param array<mixed> $rawSteps
     *
     * @return array{errors: array<string, list<string>>}
     */
    public function update(array $rawSteps): array
    {
        $result = $this->normalizer->normalize($rawSteps);
        if ([] !== $result['errors']) {
            return ['errors' => ['steps' => $result['errors']]];
        }

        $now = new \DateTimeImmutable();
        $guide = $this->repository->get();
        if (null === $guide) {
            $guide = ArchipelagoGuide::create($result['steps'], $now);
        } else {
            $guide->update($result['steps'], $now);
        }
        $this->repository->save($guide);

        return ['errors' => []];
    }
}
