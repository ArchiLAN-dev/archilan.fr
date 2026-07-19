<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Command;

use App\GameSelection\Application\Support\InstallStepsNormalizer;
use App\GameSelection\Domain\Entity\ArchipelagoGuide;
use App\GameSelection\Domain\Repository\ArchipelagoGuideRepositoryInterface;
use App\Shared\Application\Exception\ValidationException;
use Psr\Clock\ClockInterface;

final readonly class UpdateArchipelagoGuide
{
    public function __construct(
        private ArchipelagoGuideRepositoryInterface $repository,
        private InstallStepsNormalizer $normalizer,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param array<mixed> $rawSteps
     *
     * @throws ValidationException when the steps are invalid
     */
    public function update(array $rawSteps): void
    {
        $result = $this->normalizer->normalize($rawSteps);
        if ([] !== $result['errors']) {
            throw new ValidationException('Le guide contient des erreurs.', ['steps' => $result['errors']]);
        }

        $now = $this->clock->now();
        $guide = $this->repository->get();
        if (null === $guide) {
            $guide = ArchipelagoGuide::create($result['steps'], $now);
        } else {
            $guide->update($result['steps'], $now);
        }
        $this->repository->save($guide);
    }
}
