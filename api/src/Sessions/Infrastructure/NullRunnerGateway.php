<?php

declare(strict_types=1);

namespace App\Sessions\Infrastructure;

use App\Sessions\Application\RunnerGatewayInterface;

final class NullRunnerGateway implements RunnerGatewayInterface
{
    /** @var array<string, string>|null */
    public static ?array $apworldUploadResult = null;

    /** @var list<array{slotName: string, apworldHash: string, playerYaml: string}>|null Records the slots passed to the last configureSession() call (test inspection). */
    public static ?array $lastConfigureSlots = null;

    public static function reset(): void
    {
        self::$apworldUploadResult = null;
        self::$lastConfigureSlots = null;
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

    public function configureSession(string $sessionId, array $slots): array
    {
        self::$lastConfigureSlots = $slots;

        return ['valid' => true, 'errors' => []];
    }

    public function generateSession(string $sessionId, string $adminPassword, ?string $seed = null): void
    {
    }

    public function launchSession(string $sessionId, string $adminPassword, string $serverPassword): void
    {
    }

    public function stopSession(string $sessionId): void
    {
    }

    public function restartSession(string $sessionId): void
    {
    }

    public function getSessionInfo(string $sessionId): ?array
    {
        return null;
    }
}
