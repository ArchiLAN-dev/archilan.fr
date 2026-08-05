<?php

declare(strict_types=1);

namespace App\Sessions\Infrastructure\Double;

use App\Sessions\Application\Port\RunnerGatewayInterface;

final class NullRunnerGateway implements RunnerGatewayInterface
{
    /** @var array<string, string>|null */
    public static ?array $apworldUploadResult = null;

    /** @var list<array{slotName: string, apworldHash: string, playerYaml: string}>|null Records the slots passed to the last configureSession() call (test inspection). */
    public static ?array $lastConfigureSlots = null;

    /** @var array{status: string, bridgePort: ?int, apPort: ?int}|null Canned getSessionInfo() return for reconciliation tests. */
    public static ?array $nextSessionInfo = null;

    /** @var array<string, array{status: string, error: string, checkedAt: string, overridden: bool, blocks: bool}> Canned preflight verdicts by hash (story 9.38 tests). */
    public static array $apworldPreflights = [];

    public static function reset(): void
    {
        self::$apworldUploadResult = null;
        self::$lastConfigureSlots = null;
        self::$nextSessionInfo = null;
        self::$apworldPreflights = [];
    }

    public function uploadApworld(string $fileContents, string $filename): array
    {
        if (null !== self::$apworldUploadResult) {
            return self::$apworldUploadResult;
        }

        return ['error' => 'runner_unavailable'];
    }

    public function fetchOptionTypes(string $hash): array
    {
        return [];
    }

    public function fetchLocationNames(string $hash): array
    {
        return [];
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

    public function fetchApworldPreflights(): array
    {
        return self::$apworldPreflights;
    }

    public function runApworldPreflight(string $hash): bool
    {
        return true;
    }

    /**
     * @return array{status: string, error: string, checkedAt: string, overridden: bool, blocks: bool}
     */
    public function overrideApworldPreflight(string $hash, bool $overridden): array
    {
        $current = self::$apworldPreflights[$hash] ?? ['status' => 'failed', 'error' => '', 'checkedAt' => '', 'overridden' => false, 'blocks' => true];
        $current['overridden'] = $overridden;
        $current['blocks'] = 'failed' === $current['status'] && !$overridden;
        self::$apworldPreflights[$hash] = $current;

        return $current;
    }

    /** @var string|null Canned regenerated template; null makes the call fail (test inspection). */
    public static ?string $regeneratedTemplate = "name: Player{number}\ngame: Null\n";

    public function setApworldTemplate(string $hash, string $template): bool
    {
        return true;
    }

    public function regenerateApworldTemplate(string $hash): array
    {
        return null !== self::$regeneratedTemplate
            ? ['template' => self::$regeneratedTemplate]
            : ['error' => 'runner_unavailable'];
    }

    public function startSlotPreflight(string $playerYaml, ?string $apworldHash): string
    {
        return 'null-preflight-job';
    }

    /**
     * @return array{status: string, error: string}
     */
    public function getSlotPreflight(string $jobId): array
    {
        return ['status' => 'passed', 'error' => ''];
    }

    public function configureSession(string $sessionId, array $slots): array
    {
        self::$lastConfigureSlots = $slots;

        return ['valid' => true, 'errors' => []];
    }

    /**
     * @param array<string, mixed> $generationOptions
     */
    public function generateSession(string $sessionId, string $adminPassword, ?string $seed = null, array $generationOptions = []): void
    {
    }

    /**
     * @param array<string, scalar> $serverOptions
     */
    public function launchSession(string $sessionId, string $adminPassword, ?string $serverPassword, array $serverOptions = []): void
    {
    }

    public function stopSession(string $sessionId): void
    {
    }

    public function restartSession(string $sessionId): void
    {
    }

    public function relaunchFromSave(string $sessionId): void
    {
    }

    public function getSessionInfo(string $sessionId): ?array
    {
        return self::$nextSessionInfo;
    }
}
