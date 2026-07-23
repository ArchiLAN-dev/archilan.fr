<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Support;

use App\GameSelection\Domain\Enum\InstallStepType;

/**
 * Read-side presenter for install-tutorial steps (story 31.10). Centralizes what used to be duplicated
 * across the game-catalog, guide and contribution queries: drop steps with an unknown type (the public
 * type guard is all-or-nothing) and expose the step as it is rendered.
 *
 * Links and images used to be dedicated fields; since story 31.11 they live inside the markdown
 * description, uploaded images being served under a stable URL by TutorialImageServeController.
 */
final readonly class InstallStepsReader
{
    /**
     * @return list<array{type: string, title: string, description: string, videoUrl: string|null}>
     */
    public function presentJson(mixed $rawJson): array
    {
        if (!is_string($rawJson) || '' === $rawJson) {
            return [];
        }

        $decoded = json_decode($rawJson, true);

        return is_array($decoded) ? $this->present($decoded) : [];
    }

    /**
     * @param array<mixed> $rawSteps
     *
     * @return list<array{type: string, title: string, description: string, videoUrl: string|null}>
     */
    public function present(array $rawSteps): array
    {
        $steps = [];

        foreach ($rawSteps as $raw) {
            if (!is_array($raw)) {
                continue;
            }

            $type = $raw['type'] ?? null;
            $title = $raw['title'] ?? null;
            if (!is_string($type) || !is_string($title) || !InstallStepType::isValid($type)) {
                continue;
            }

            $description = $raw['description'] ?? null;
            $videoUrl = $raw['videoUrl'] ?? null;

            $steps[] = [
                'type' => $type,
                'title' => $title,
                'description' => is_string($description) ? $description : '',
                'videoUrl' => is_string($videoUrl) ? $videoUrl : null,
            ];
        }

        return $steps;
    }
}
