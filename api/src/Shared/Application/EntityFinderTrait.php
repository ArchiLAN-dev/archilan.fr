<?php

declare(strict_types=1);

namespace App\Shared\Application;

use Doctrine\ORM\EntityManagerInterface;

/**
 * @property EntityManagerInterface $entityManager
 */
trait EntityFinderTrait
{
    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     *
     * @throws \RuntimeException when the entity is not found
     */
    protected function findOrFail(string $class, string $id): object
    {
        $entity = $this->entityManager->find($class, $id);

        if (null === $entity) {
            throw new \RuntimeException(sprintf('%s not found: %s', $class, $id));
        }

        return $entity;
    }
}
