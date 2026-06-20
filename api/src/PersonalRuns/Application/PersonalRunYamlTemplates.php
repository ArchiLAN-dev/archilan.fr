<?php

declare(strict_types=1);

namespace App\PersonalRuns\Application;

use App\GameSelection\Domain\Game;
use App\GameSelection\Domain\GameRepositoryInterface;
use App\PersonalRuns\Domain\YamlTemplate;
use App\PersonalRuns\Domain\YamlTemplateRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Member-owned, named, reusable YAML configurations per game (story 16.11). Cohesive read+write
 * service for the personal-run slot editor, mirroring the local PersonalRunGameSelection style.
 */
final readonly class PersonalRunYamlTemplates
{
    private const NAME_MAX_LENGTH = 80;

    public function __construct(
        private YamlTemplateRepositoryInterface $templates,
        private GameRepositoryInterface $games,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return list<array{id: string, name: string, gameId: string, yaml: string, updatedAt: string}>
     */
    public function list(string $userId, string $gameId): array
    {
        return array_map(
            fn (YamlTemplate $t): array => $this->toArray($t),
            $this->templates->findByUserAndGame($userId, $gameId),
        );
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{found: bool, errorCode: string|null, errors: array<string, list<string>>, template: array{id: string, name: string, gameId: string, yaml: string, updatedAt: string}|null}
     */
    public function save(string $userId, array $input): array
    {
        $gameId = is_string($input['gameId'] ?? null) ? trim($input['gameId']) : '';
        if ('' === $gameId) {
            return $this->error('game_required', ['gameId' => ['Le jeu est requis.']]);
        }

        $game = $this->games->findById($gameId);
        if (!$game instanceof Game) {
            return $this->error('unknown_game', ['gameId' => ['Jeu introuvable dans la bibliothèque.']]);
        }
        if (!$game->isApworldReady()) {
            return $this->error('game_not_ready', ['gameId' => ["Ce jeu n'a pas encore de fichier .apworld configuré."]]);
        }

        $nameResult = $this->validateName($input);
        if (null !== $nameResult['errorCode']) {
            return $this->error($nameResult['errorCode'], $nameResult['errors']);
        }
        $name = $nameResult['name'];

        $yamlResult = $this->validateYaml($input);
        if (null !== $yamlResult['errorCode']) {
            return $this->error($yamlResult['errorCode'], $yamlResult['errors']);
        }

        if ($this->templates->existsByUserGameName($userId, $gameId, $name)) {
            return $this->error('template_name_taken', ['name' => ['Un template porte déjà ce nom pour ce jeu.']]);
        }

        $template = YamlTemplate::create($userId, $gameId, $name, $yamlResult['yaml'], new \DateTimeImmutable());
        $this->templates->save($template);

        $this->logger->info('personal_run.yaml_template_saved', ['userId' => $userId, 'gameId' => $gameId, 'templateId' => $template->getId()]);

        return $this->ok($template);
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{found: bool, errorCode: string|null, errors: array<string, list<string>>, template: array{id: string, name: string, gameId: string, yaml: string, updatedAt: string}|null}
     */
    public function update(string $userId, string $id, array $input): array
    {
        $template = $this->templates->findById($id);
        if (!$template instanceof YamlTemplate || !$template->isOwnedBy($userId)) {
            return $this->notFound();
        }

        $hasName = \array_key_exists('name', $input);
        $hasYaml = \array_key_exists('yaml', $input);
        if (!$hasName && !$hasYaml) {
            return $this->error('nothing_to_update', ['_' => ['Aucune modification fournie.']]);
        }

        $now = new \DateTimeImmutable();

        if ($hasName) {
            $nameResult = $this->validateName($input);
            if (null !== $nameResult['errorCode']) {
                return $this->error($nameResult['errorCode'], $nameResult['errors']);
            }
            if ($this->templates->existsByUserGameName($userId, $template->getGameId(), $nameResult['name'], $id)) {
                return $this->error('template_name_taken', ['name' => ['Un template porte déjà ce nom pour ce jeu.']]);
            }
            $template->rename($nameResult['name'], $now);
        }

        if ($hasYaml) {
            $yamlResult = $this->validateYaml($input);
            if (null !== $yamlResult['errorCode']) {
                return $this->error($yamlResult['errorCode'], $yamlResult['errors']);
            }
            $template->updateYaml($yamlResult['yaml'], $now);
        }

        $this->templates->flush();

        $this->logger->info('personal_run.yaml_template_updated', ['userId' => $userId, 'templateId' => $id]);

        return $this->ok($template);
    }

    /**
     * @return array{found: bool, errorCode: string|null, errors: array<string, list<string>>, template: null}
     */
    public function delete(string $userId, string $id): array
    {
        $template = $this->templates->findById($id);
        if (!$template instanceof YamlTemplate || !$template->isOwnedBy($userId)) {
            return $this->notFound();
        }

        $this->templates->delete($template);

        $this->logger->info('personal_run.yaml_template_deleted', ['userId' => $userId, 'templateId' => $id]);

        return ['found' => true, 'errorCode' => null, 'errors' => [], 'template' => null];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{name: string, errorCode: string|null, errors: array<string, list<string>>}
     */
    private function validateName(array $input): array
    {
        $name = is_string($input['name'] ?? null) ? trim($input['name']) : '';
        if ('' === $name) {
            return ['name' => '', 'errorCode' => 'name_required', 'errors' => ['name' => ['Le nom est requis.']]];
        }
        if (mb_strlen($name) > self::NAME_MAX_LENGTH) {
            return ['name' => '', 'errorCode' => 'name_too_long', 'errors' => ['name' => [sprintf('Le nom ne peut dépasser %d caractères.', self::NAME_MAX_LENGTH)]]];
        }

        return ['name' => $name, 'errorCode' => null, 'errors' => []];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{yaml: string, errorCode: string|null, errors: array<string, list<string>>}
     */
    private function validateYaml(array $input): array
    {
        $yaml = is_string($input['yaml'] ?? null) ? $input['yaml'] : '';
        if ('' === trim($yaml)) {
            return ['yaml' => '', 'errorCode' => 'yaml_required', 'errors' => ['yaml' => ['La configuration YAML est requise.']]];
        }

        try {
            Yaml::parse($yaml);
        } catch (ParseException) {
            return ['yaml' => '', 'errorCode' => 'invalid_yaml', 'errors' => ['yaml' => ['La configuration YAML est invalide.']]];
        }

        return ['yaml' => $yaml, 'errorCode' => null, 'errors' => []];
    }

    /**
     * @return array{id: string, name: string, gameId: string, yaml: string, updatedAt: string}
     */
    private function toArray(YamlTemplate $template): array
    {
        return [
            'id' => $template->getId(),
            'name' => $template->getName(),
            'gameId' => $template->getGameId(),
            'yaml' => $template->getYaml(),
            'updatedAt' => $template->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @return array{found: bool, errorCode: string|null, errors: array<string, list<string>>, template: array{id: string, name: string, gameId: string, yaml: string, updatedAt: string}}
     */
    private function ok(YamlTemplate $template): array
    {
        return ['found' => true, 'errorCode' => null, 'errors' => [], 'template' => $this->toArray($template)];
    }

    /**
     * @param array<string, list<string>> $errors
     *
     * @return array{found: bool, errorCode: string|null, errors: array<string, list<string>>, template: null}
     */
    private function error(string $errorCode, array $errors): array
    {
        return ['found' => true, 'errorCode' => $errorCode, 'errors' => $errors, 'template' => null];
    }

    /**
     * @return array{found: bool, errorCode: string|null, errors: array<string, list<string>>, template: null}
     */
    private function notFound(): array
    {
        return ['found' => false, 'errorCode' => null, 'errors' => [], 'template' => null];
    }
}
