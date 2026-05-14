<?php

declare(strict_types=1);

namespace App\Sessions\Infrastructure;

final class NullRunnerGateway implements RunnerGatewayInterface
{
    /** @var array<string, string>|null */
    public static ?array $apworldUploadResult = null;

    public static function reset(): void
    {
        self::$apworldUploadResult = null;
    }

    public function uploadApworld(string $fileContents, string $filename): array
    {
        if (null !== self::$apworldUploadResult) {
            return self::$apworldUploadResult;
        }

        return ['error' => 'runner_unavailable'];
    }

    public function preflight(string $sessionId, array $slots): array
    {
        $proposed = [];
        $valid = true;
        foreach ($slots as $slot) {
            $errors = [];
            $archipelagoGameName = is_string($slot['archipelagoGameName'] ?? null) ? $slot['archipelagoGameName'] : '';
            if ('' === trim($archipelagoGameName)) {
                $errors[] = "Ce jeu n'a pas de nom Archipelago configure.";
            }

            if ([] !== $errors) {
                $valid = false;
            }

            $proposed[] = [
                'slotId' => $slot['slotId'] ?? '',
                'proposedName' => substr((is_string($slot['playerName'] ?? null) ? $slot['playerName'] : 'Player').'_Unknown1', 0, 16),
                'errors' => $errors,
            ];
        }

        return ['valid' => $valid, 'slots' => $proposed];
    }

    public function writeYamls(string $sessionId, array $slots): array
    {
        return ['files' => []];
    }

    public function generate(string $sessionId): array
    {
        return ['sessionId' => $sessionId, 'status' => 'generating'];
    }

    public function launch(string $sessionId): array
    {
        return ['sessionId' => $sessionId, 'status' => 'running'];
    }

    public function restart(string $sessionId): array
    {
        return ['sessionId' => $sessionId, 'status' => 'running'];
    }

    public function stop(string $sessionId): array
    {
        return ['sessionId' => $sessionId, 'status' => 'stopped'];
    }

    public function getYamlsZip(string $sessionId): ?string
    {
        return null;
    }
}
