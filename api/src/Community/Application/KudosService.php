<?php

declare(strict_types=1);

namespace App\Community\Application;

use App\Community\Domain\Kudos;
use App\Community\Domain\KudosRepositoryInterface;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * Kudos toggling + batch state reads (story 30.11). Idempotent under concurrency.
 */
final readonly class KudosService
{
    public function __construct(private KudosRepositoryInterface $kudos)
    {
    }

    /**
     * Toggle the actor's kudos on a target; returns the resulting state.
     *
     * @return array{given: bool, count: int}
     */
    public function toggle(string $actorId, string $targetType, string $targetId): array
    {
        $existing = $this->kudos->find($actorId, $targetType, $targetId);
        if (null !== $existing) {
            $this->kudos->remove($existing);

            return ['given' => false, 'count' => $this->kudos->count($targetType, $targetId)];
        }

        try {
            $this->kudos->save(Kudos::give($actorId, $targetType, $targetId, new \DateTimeImmutable()));
        } catch (UniqueConstraintViolationException) {
            // Concurrent give - already recorded.
        }

        return ['given' => true, 'count' => $this->kudos->count($targetType, $targetId)];
    }

    /**
     * Batch count + viewer-given state for a set of targets.
     *
     * @param list<array{targetType: string, targetId: string}> $targets
     *
     * @return array<string, array{count: int, given: bool}> keyed by "{type}:{id}"
     */
    public function state(?string $viewerId, array $targets): array
    {
        /** @var array<string, list<string>> $byType */
        $byType = [];
        foreach ($targets as $target) {
            if (Kudos::isValidTargetType($target['targetType'])) {
                $byType[$target['targetType']][] = $target['targetId'];
            }
        }

        $result = [];
        foreach ($byType as $type => $ids) {
            $ids = array_values(array_unique($ids));
            $counts = $this->kudos->countsFor($type, $ids);
            $given = null === $viewerId ? [] : array_flip($this->kudos->givenBy($viewerId, $type, $ids));
            foreach ($ids as $id) {
                $result[$type.':'.$id] = [
                    'count' => $counts[$id] ?? 0,
                    'given' => isset($given[$id]),
                ];
            }
        }

        return $result;
    }
}
