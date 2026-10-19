<?php

declare(strict_types=1);

namespace App\Sessions\Infrastructure\Double;

use App\Sessions\Application\Exception\RequiredGenerationFileExpiredException;
use App\Sessions\Application\Exception\RequiredGenerationFileRefusedException;
use App\Sessions\Application\Port\RunnerGatewayInterface;

final class NullRunnerGateway implements RunnerGatewayInterface
{
    /** @var array<string, string>|null */
    public static ?array $apworldUploadResult = null;

    /** @var list<array{slotName: string, apworldHash: string, playerYaml: string}>|null Records the slots passed to the last configureSession() call (test inspection). */
    public static ?array $lastConfigureSlots = null;

    /** @var array{status: string, bridgePort: ?int, apPort: ?int}|null Canned getSessionInfo() return for reconciliation tests. */
    public static ?array $nextSessionInfo = null;

    /** @var array<string, array{status: string, error: string, checkedAt: string, overridden: bool, blocks: bool, image?: string|null, imageId?: string|null, warning?: string, withRequiredFiles?: bool}> Canned preflight verdicts by hash (story 9.38 tests). */
    public static array $apworldPreflights = [];

    /** @var array{apImage: string, apImageId: string|null}|null Canned image in use (story 38.8 tests); null = runner silent. */
    public static ?array $runtime = null;

    /** @var array{playerYaml: string, apworldHash: ?string, requiredFileIds: list<string>}|null The last startSlotPreflight() call (stories 38.14, 44.6 tests). */
    public static ?array $lastSlotPreflight = null;

    /** @var array{status: string, error: string}|null Canned getSlotPreflight() result; null = an unknown job (story 38.14 tests). */
    public static ?array $slotPreflightResult = ['status' => 'passed', 'error' => ''];

    /** When true, startSlotPreflight() reads as a runner that did not answer (story 38.14 tests). */
    public static bool $slotPreflightUnavailable = false;

    /** @var list<array{setting: string, filename: string, content: string}> Files handed to stageRequiredFile(), read at the call (story 44.2 tests). */
    public static array $stagedFiles = [];

    /** @var list<string> Ids passed to releaseRequiredFile() (story 44.2 tests). */
    public static array $releasedFileIds = [];

    /** @var list<list<string>> The requiredFileIds of each generateSession() call (story 44.2 tests). */
    public static array $generatedWithFileIds = [];

    /** When set, stageRequiredFile() refuses the file whose setting it names (story 44.2 tests). */
    public static ?string $refuseStagingOf = null;

    /** When true, generateSession() reads as an orchestrator that no longer holds the files (story 44.2 tests). */
    public static bool $requiredFilesExpired = false;

    /** @var list<array{hash: string, requiredFileIds: list<string>}> Each runApworldPreflight() call (story 44.6 tests). */
    public static array $apworldPreflightRuns = [];

    /** When true, runApworldPreflight() reads as a runner that did not answer (story 44.6 tests). */
    public static bool $apworldPreflightUnavailable = false;

    /** @var array<string, list<array{setting: string, file: string, extension: string, description: ?string, md5s: list<string>}>> Canned declared files by apworld hash (story 44.3 tests). */
    public static array $declaredFiles = [];

    /** @var array<string, list<array{setting: string, file: string, extension: string, description: ?string, md5s: list<string>}>>|null Canned files of the image's own worlds; null = runner silent (story 44.3 tests). */
    public static ?array $officialDeclaredFiles = null;

    public static function reset(): void
    {
        self::$declaredFiles = [];
        self::$officialDeclaredFiles = null;
        self::$apworldPreflightRuns = [];
        self::$apworldPreflightUnavailable = false;
        self::$stagedFiles = [];
        self::$releasedFileIds = [];
        self::$generatedWithFileIds = [];
        self::$refuseStagingOf = null;
        self::$requiredFilesExpired = false;
        self::$lastSlotPreflight = null;
        self::$slotPreflightResult = ['status' => 'passed', 'error' => ''];
        self::$slotPreflightUnavailable = false;
        self::$apworldUploadResult = null;
        self::$lastConfigureSlots = null;
        self::$nextSessionInfo = null;
        self::$apworldPreflights = [];
        self::$runtime = null;
    }

    public function uploadApworld(string $fileContents, string $filename): array
    {
        if (null !== self::$apworldUploadResult) {
            return self::$apworldUploadResult;
        }

        return ['error' => 'runner_unavailable'];
    }

    /** A plain world (story 38.6 review): an empty introspection reads as an orchestrator that did not answer. */
    public function fetchOptionTypes(string $hash): array
    {
        return ['accessibility' => ['type' => 'choice', 'values' => ['full', 'minimal']]];
    }

    public function fetchLocationNames(string $hash): array
    {
        return ['Null Location'];
    }

    public function fetchItems(string $hash): array
    {
        return ['items' => ['Null Item', 'Other Item'], 'groups' => ['Null Group' => ['Null Item']]];
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

    public function fetchDeclaredFiles(string $hash): array
    {
        return self::$declaredFiles[$hash] ?? [];
    }

    public function fetchOfficialDeclaredFiles(): ?array
    {
        return self::$officialDeclaredFiles;
    }

    public function fetchRuntime(): ?array
    {
        return self::$runtime;
    }

    public function runApworldPreflight(string $hash, array $requiredFileIds = []): bool
    {
        if (self::$apworldPreflightUnavailable) {
            return false;
        }
        self::$apworldPreflightRuns[] = ['hash' => $hash, 'requiredFileIds' => $requiredFileIds];

        return true;
    }

    /**
     * @return array{status: string, error: string, checkedAt: string, overridden: bool, blocks: bool, image?: string|null, imageId?: string|null, warning?: string, withRequiredFiles?: bool}
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

    public function reintrospectApworld(string $hash): bool
    {
        return false;
    }

    public function regenerateApworldTemplate(string $hash): array
    {
        return null !== self::$regeneratedTemplate
            ? ['template' => self::$regeneratedTemplate]
            : ['error' => 'runner_unavailable'];
    }

    public function startSlotPreflight(string $playerYaml, ?string $apworldHash, array $requiredFileIds = []): ?string
    {
        if (self::$slotPreflightUnavailable) {
            return null;
        }
        self::$lastSlotPreflight = ['playerYaml' => $playerYaml, 'apworldHash' => $apworldHash, 'requiredFileIds' => $requiredFileIds];

        return 'null-preflight-job';
    }

    /**
     * @return array{status: string, error: string}|null
     */
    public function getSlotPreflight(string $jobId): ?array
    {
        return self::$slotPreflightResult;
    }

    public function configureSession(string $sessionId, array $slots): array
    {
        self::$lastConfigureSlots = $slots;

        return ['valid' => true, 'errors' => []];
    }

    /**
     * @param array<string, mixed> $generationOptions
     */
    public function generateSession(string $sessionId, string $adminPassword, ?string $seed = null, array $generationOptions = [], array $requiredFileIds = []): void
    {
        self::$generatedWithFileIds[] = $requiredFileIds;
        if ([] !== $requiredFileIds && self::$requiredFilesExpired) {
            throw new RequiredGenerationFileExpiredException();
        }
    }

    public function stageRequiredFile(string $setting, string $path, string $filename): string
    {
        if ($setting === self::$refuseStagingOf) {
            throw new RequiredGenerationFileRefusedException('file too large');
        }
        self::$stagedFiles[] = ['setting' => $setting, 'filename' => $filename, 'content' => (string) file_get_contents($path)];

        return 'staged-'.count(self::$stagedFiles);
    }

    public function releaseRequiredFile(string $stagedFileId): void
    {
        self::$releasedFileIds[] = $stagedFileId;
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
