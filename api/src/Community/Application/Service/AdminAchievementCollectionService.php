<?php

declare(strict_types=1);

namespace App\Community\Application\Service;

use App\Community\Application\Support\AchievementImageUrlResolver;
use App\Community\Application\Support\CosmeticRewardCatalog;
use App\Community\Domain\Entity\AchievementCollection;
use App\Community\Domain\Repository\AchievementCollectionRepositoryInterface;
use App\Community\Domain\Repository\AchievementDefinitionRepositoryInterface;
use App\Community\Domain\ValueObject\CosmeticReward;
use Psr\Clock\ClockInterface;

/**
 * Story 30.52: the admin side of the collections of achievements - create, edit, order, delete. A deleted
 * collection sends its achievements back to « Autres succès ».
 *
 * @phpstan-type CollectionView array{id: string, name: string, description: string, imageKey: string|null, imageUrl: string|null, position: int, secret: bool, reward: array{type: string, key: string, label: string}|null, pelles: int}
 */
final readonly class AdminAchievementCollectionService
{
    public function __construct(
        private AchievementCollectionRepositoryInterface $collections,
        private AchievementDefinitionRepositoryInterface $definitions,
        private AchievementImageUrlResolver $imageUrls,
        private CosmeticRewardCatalog $cosmetics,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return list<CollectionView>
     */
    public function list(): array
    {
        return array_map($this->present(...), $this->collections->all());
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return CollectionView
     *
     * @throws \InvalidArgumentException
     */
    public function create(array $payload): array
    {
        $now = $this->clock->now();
        $collection = AchievementCollection::create($this->text($payload, 'name'), $this->text($payload, 'description'), $this->collections->maxPosition() + 1, $now);
        $this->apply($collection, $payload, $now);
        $this->collections->save($collection);

        return $this->present($collection);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return CollectionView|null
     *
     * @throws \InvalidArgumentException
     */
    public function update(string $id, array $payload): ?array
    {
        $collection = $this->collections->findById($id);
        if (!$collection instanceof AchievementCollection) {
            return null;
        }
        $now = $this->clock->now();
        $collection->rename($this->text($payload, 'name'), $this->text($payload, 'description'), $now);
        $this->apply($collection, $payload, $now);
        $this->collections->flush();

        return $this->present($collection);
    }

    public function delete(string $id): bool
    {
        $collection = $this->collections->findById($id);
        if (!$collection instanceof AchievementCollection) {
            return false;
        }
        $now = $this->clock->now();
        foreach ($this->definitions->all() as $definition) {
            if ($id === $definition->getCollectionId()) {
                $definition->moveToCollection(null, $now);
            }
        }
        $this->definitions->flush();
        $this->collections->remove($collection);

        return true;
    }

    /**
     * @param list<string> $orderedIds
     */
    public function reorder(array $orderedIds): void
    {
        $now = $this->clock->now();
        $position = 0;
        foreach ($orderedIds as $id) {
            $collection = $this->collections->findById($id);
            if ($collection instanceof AchievementCollection) {
                $collection->reorder($position, $now);
                ++$position;
            }
        }
        $this->collections->flush();
    }

    /**
     * The optional fields: secret, image, reward. Each is only touched when present, like an achievement's image.
     *
     * @param array<string, mixed> $payload
     *
     * @throws \InvalidArgumentException
     */
    private function apply(AchievementCollection $collection, array $payload, \DateTimeImmutable $now): void
    {
        if (array_key_exists('secret', $payload)) {
            $collection->markSecret(true === $payload['secret'], $now);
        }
        if (array_key_exists('imageKey', $payload)) {
            $key = is_string($payload['imageKey']) ? trim($payload['imageKey']) : '';
            $collection->updateImage('' === $key ? null : $key, $now);
        }
        if (array_key_exists('reward', $payload) || array_key_exists('pelles', $payload)) {
            $reward = array_key_exists('reward', $payload) ? $this->reward($payload['reward']) : $collection->getCosmeticReward();
            $pelles = array_key_exists('pelles', $payload) ? $payload['pelles'] : $collection->getRewardPelles();
            if (!is_int($pelles)) {
                throw new \InvalidArgumentException('Les pelles sont un nombre entier.');
            }
            $collection->rewardWith($reward, $pelles, $now);
        }
    }

    /**
     * @throws \InvalidArgumentException
     */
    private function reward(mixed $raw): ?CosmeticReward
    {
        if (null === $raw) {
            return null;
        }
        try {
            $reward = is_array($raw) ? CosmeticReward::fromParts($raw['type'] ?? null, $raw['key'] ?? null) : throw new \DomainException('cosmetic_reward_invalid');
        } catch (\DomainException) {
            throw new \InvalidArgumentException('Récompense invalide.');
        }
        if (null !== $reward && !$this->cosmetics->exists($reward)) {
            throw new \InvalidArgumentException('Ce cosmétique n\'existe pas.');
        }

        return $reward;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function text(array $payload, string $field): string
    {
        return is_string($payload[$field] ?? null) ? $payload[$field] : '';
    }

    /**
     * @return CollectionView
     */
    private function present(AchievementCollection $c): array
    {
        $reward = $c->getCosmeticReward();

        return [
            'id' => $c->getId(),
            'name' => $c->getName(),
            'description' => $c->getDescription(),
            'imageKey' => $c->getImageKey(),
            'imageUrl' => $this->imageUrls->resolve($c->getImageKey()),
            'position' => $c->getPosition(),
            'secret' => $c->isSecret(),
            'reward' => null === $reward ? null : [...$reward->toArray(), 'label' => $this->cosmetics->label($reward)],
            'pelles' => $c->getRewardPelles(),
        ];
    }
}
