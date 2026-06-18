<?php

declare(strict_types=1);

namespace App\Community\Application;

use App\Community\Domain\AchievementDefinition;
use App\Community\Domain\AchievementDefinitionRepositoryInterface;
use App\Community\Domain\AchievementMetricCatalog;
use App\Community\Domain\AchievementOperator;
use App\Community\Domain\AchievementRuleFactory;
use App\Community\Domain\AchievementRuleGroup;
use App\Community\Domain\InvalidAchievementRuleException;

/**
 * Admin CRUD for achievement definitions (story 30.16): validate + persist the composable rule trees.
 * `key` is immutable after creation; the rule is validated through AchievementRuleFactory.
 */
final readonly class AdminAchievementService
{
    private const KEY_PATTERN = '/^[a-z0-9_]{1,64}$/';

    public function __construct(private AchievementDefinitionRepositoryInterface $definitions)
    {
    }

    /**
     * @return list<array{id: string, key: string, name: string, description: string, rule: array<string, mixed>, active: bool, position: int}>
     */
    public function list(): array
    {
        return array_map(static fn (AchievementDefinition $d): array => self::present($d), $this->definitions->all());
    }

    /**
     * The admin dashboard payload: every definition plus the rule-builder option lists, in one read.
     *
     * @return array{definitions: list<array{id: string, key: string, name: string, description: string, rule: array<string, mixed>, active: bool, position: int}>, options: array{facts: list<array{key: string, label: string}>, operators: list<string>, groupOps: list<string>}}
     */
    public function dashboard(): array
    {
        return ['definitions' => $this->list(), 'options' => $this->formOptions()];
    }

    /**
     * Option lists for the admin rule builder (facts, operators, group operators).
     *
     * @return array{facts: list<array{key: string, label: string}>, operators: list<string>, groupOps: list<string>}
     */
    public function formOptions(): array
    {
        $facts = [];
        foreach (AchievementMetricCatalog::facts() as $key => $label) {
            $facts[] = ['key' => $key, 'label' => $label];
        }

        return [
            'facts' => $facts,
            'operators' => array_map(static fn (AchievementOperator $o): string => $o->value, AchievementOperator::cases()),
            'groupOps' => AchievementRuleGroup::OPS,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{id: string, key: string, name: string, description: string, rule: array<string, mixed>, active: bool, position: int}
     *
     * @throws InvalidAchievementRuleException
     * @throws \InvalidArgumentException
     */
    public function create(array $payload): array
    {
        $key = $this->requireKey($payload);
        if ($this->definitions->existsByKey($key)) {
            throw new \InvalidArgumentException('Cette clé existe déjà.');
        }
        $name = $this->requireName($payload);
        $rule = AchievementRuleFactory::fromArray($this->ruleArray($payload))->toArray();
        $now = new \DateTimeImmutable();

        $definition = AchievementDefinition::create($key, $name, $this->description($payload), $rule, $this->definitions->maxPosition() + 1, $now);
        $this->definitions->save($definition);

        return self::present($definition);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{id: string, key: string, name: string, description: string, rule: array<string, mixed>, active: bool, position: int}|null
     *
     * @throws InvalidAchievementRuleException
     * @throws \InvalidArgumentException
     */
    public function update(string $id, array $payload): ?array
    {
        $definition = $this->definitions->findById($id);
        if (!$definition instanceof AchievementDefinition) {
            return null;
        }

        $name = $this->requireName($payload);
        $rule = AchievementRuleFactory::fromArray($this->ruleArray($payload))->toArray();
        $definition->update($name, $this->description($payload), $rule, new \DateTimeImmutable());
        $this->definitions->flush();

        return self::present($definition);
    }

    public function setActive(string $id, bool $active): bool
    {
        $definition = $this->definitions->findById($id);
        if (!$definition instanceof AchievementDefinition) {
            return false;
        }

        $definition->setActive($active, new \DateTimeImmutable());
        $this->definitions->flush();

        return true;
    }

    /**
     * @param list<string> $orderedIds
     */
    public function reorder(array $orderedIds): void
    {
        $now = new \DateTimeImmutable();
        $position = 0;
        foreach ($orderedIds as $id) {
            $definition = $this->definitions->findById($id);
            if ($definition instanceof AchievementDefinition) {
                $definition->reorder($position, $now);
                ++$position;
            }
        }
        $this->definitions->flush();
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function requireKey(array $payload): string
    {
        $key = is_string($payload['key'] ?? null) ? trim($payload['key']) : '';
        if (1 !== preg_match(self::KEY_PATTERN, $key)) {
            throw new \InvalidArgumentException('Clé invalide (minuscules, chiffres, underscore).');
        }

        return $key;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function requireName(array $payload): string
    {
        $name = is_string($payload['name'] ?? null) ? trim($payload['name']) : '';
        if ('' === $name) {
            throw new \InvalidArgumentException('Le nom est requis.');
        }

        return mb_substr($name, 0, 191);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function description(array $payload): string
    {
        return is_string($payload['description'] ?? null) ? trim($payload['description']) : '';
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<mixed, mixed>
     */
    private function ruleArray(array $payload): array
    {
        $rule = $payload['rule'] ?? null;
        if (!is_array($rule)) {
            throw new InvalidAchievementRuleException('A rule is required.');
        }

        return $rule;
    }

    /**
     * @return array{id: string, key: string, name: string, description: string, rule: array<string, mixed>, active: bool, position: int}
     */
    private static function present(AchievementDefinition $d): array
    {
        return [
            'id' => $d->getId(),
            'key' => $d->getKey(),
            'name' => $d->getName(),
            'description' => $d->getDescription(),
            'rule' => $d->getRule(),
            'active' => $d->isActive(),
            'position' => $d->getPosition(),
        ];
    }
}
