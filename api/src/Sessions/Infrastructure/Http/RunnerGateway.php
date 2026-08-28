<?php

declare(strict_types=1);

namespace App\Sessions\Infrastructure\Http;

use App\Sessions\Application\Port\RunnerGatewayInterface;
use Archilan\OrchestratorClient\Apworlds\Response\ApworldPreflight;
use Archilan\OrchestratorClient\Apworlds\Response\ChoiceTemplateOption;
use Archilan\OrchestratorClient\Apworlds\Response\DictSubOption;
use Archilan\OrchestratorClient\Apworlds\Response\DictTemplateOption;
use Archilan\OrchestratorClient\Apworlds\Response\RangeTemplateOption;
use Archilan\OrchestratorClient\Apworlds\Response\TemplateOption;
use Archilan\OrchestratorClient\Apworlds\Response\TextTemplateOption;
use Archilan\OrchestratorClient\Apworlds\Response\ToggleTemplateOption;
use Archilan\OrchestratorClient\Apworlds\Response\WeightsTemplateOption;
use Archilan\OrchestratorClient\OrchestratorClient;
use Archilan\OrchestratorClient\Sessions\Request\ConfigureRequest;
use Archilan\OrchestratorClient\Sessions\Request\ConfigureSlot;
use Archilan\OrchestratorClient\Sessions\Request\PreflightRequest;
use Archilan\OrchestratorClient\Sessions\Request\PreflightSlot;
use Archilan\OrchestratorClient\Sessions\Response\PreflightSlotResult;
use Archilan\OrchestratorClient\Sessions\Yaml\PlayerYaml;
use Psr\Log\LoggerInterface;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final readonly class RunnerGateway implements RunnerGatewayInterface
{
    public function __construct(
        private OrchestratorClient $client,
        private LoggerInterface $logger,
    ) {
    }

    public function preflight(string $sessionId, array $slots): array
    {
        try {
            $preflightSlots = [];
            foreach ($slots as $slot) {
                $preflightSlots[] = new PreflightSlot(
                    slotId: is_string($slot['slotId'] ?? null) ? $slot['slotId'] : '',
                    playerName: is_string($slot['playerName'] ?? null) ? $slot['playerName'] : '',
                    archipelagoGameName: is_string($slot['archipelagoGameName'] ?? null) ? $slot['archipelagoGameName'] : '',
                );
            }

            $result = $this->client->sessions()->preflight($sessionId, new PreflightRequest($preflightSlots));

            return [
                'valid' => $result->valid,
                'slots' => array_map(static fn (PreflightSlotResult $s) => [
                    'slotId' => $s->slotId,
                    'proposedName' => $s->proposedName,
                    'errors' => $s->errors,
                ], $result->slots),
            ];
        } catch (\Throwable $e) {
            $this->logger->error('runner.preflight_failed', ['sessionId' => $sessionId, 'error' => $e->getMessage()]);

            return ['error' => 'runner_unavailable'];
        }
    }

    public function uploadApworld(string $fileContents, string $filename): array
    {
        try {
            $result = $this->client->apworlds()->upload($fileContents, $filename);

            // Resolve archipelagoGameName: upload response only returns hash + options,
            // so we fetch the apworld list and match by hash. Non-fatal if list() fails.
            $archipelagoGameName = '';
            try {
                foreach ($this->client->apworlds()->list() as $entry) {
                    if ($entry->hash === $result->hash) {
                        $archipelagoGameName = $entry->game;
                        break;
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->warning('runner.apworld_list_failed', ['hash' => $result->hash, 'error' => $e->getMessage()]);
            }

            // Fetch the default YAML template; non-fatal if it fails
            $defaultYaml = '';
            try {
                $defaultYaml = $this->client->apworlds()->getYamlTemplate($result->hash);
            } catch (\Throwable $e) {
                $this->logger->warning('runner.apworld_yaml_fetch_failed', ['hash' => $result->hash, 'error' => $e->getMessage()]);
            }

            return [
                'storageKey' => $result->hash.'.apworld',
                'hash' => $result->hash,
                'archipelagoGameName' => $archipelagoGameName,
                'defaultYaml' => $defaultYaml,
                'optionTypes' => $this->fetchOptionTypes($result->hash),
                'locationNames' => $this->fetchLocationNames($result->hash),
            ];
        } catch (\Throwable $e) {
            $this->logger->error('runner.apworld_upload_failed', ['filename' => $filename, 'error' => $e->getMessage()]);

            return $this->classifyUploadError($e->getMessage());
        }
    }

    public function fetchOptionTypes(string $hash): array
    {
        $types = [];
        try {
            foreach ($this->client->apworlds()->getOptions($hash) as $option) {
                $types[$option->key] = self::describeOption($option);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('runner.apworld_options_fetch_failed', ['hash' => $hash, 'error' => $e->getMessage()]);
        }

        return $types;
    }

    /**
     * Story 9.33: what the apworld actually says an option is.
     *
     * Only range bounds used to survive this method, so the editor had to guess every other option
     * from the *shape of its value* - keys `true`/`false` mean toggle, numeric keys mean range, and
     * everything else falls to choice. That heuristic is what mis-read Pokemon Platinum's literal
     * `game_options` dict and coerced a player name to an int, crashing generation (story 4.17).
     *
     * The orchestrator already merges introspected types into this response; nothing upstream had to
     * change, the type simply stopped being dropped here.
     *
     * @return array{type: string, min?: int, max?: int, default?: int|string|bool|null, values?: list<string>, keys?: array<string, array{values: list<string>}>}
     */
    private static function describeOption(TemplateOption $option): array
    {
        if ($option instanceof RangeTemplateOption) {
            return [
                'type' => 'range',
                // Kept at the top level and unrenamed: the range-bounds consumers of stories 9.25
                // and 4.16 read them exactly here.
                'min' => $option->rangeMin,
                'max' => $option->rangeMax,
                'default' => $option->default,
            ];
        }

        if ($option instanceof ChoiceTemplateOption) {
            return ['type' => 'choice', 'default' => $option->default, 'values' => array_values(array_filter($option->validValues, is_string(...)))];
        }

        if ($option instanceof ToggleTemplateOption) {
            return ['type' => 'toggle', 'default' => $option->default];
        }

        if ($option instanceof DictTemplateOption) {
            // A mapping of setting names to literal values, not a weighted distribution. Telling the
            // editor which it is stops it from running a player name through a weight coercion
            // (story 4.17, whose guard stays as the fallback for apworlds not re-introspected).
            //
            // Mind the two fields: `values` carries the sub-setting NAMES (`validKeys`), `keys`
            // carries the values each of them accepts (story 9.51). They look alike on the wire and
            // mean opposite things - swapping them puts key names in the player's dropdown.
            $spec = ['type' => 'dict', 'values' => array_values($option->validKeys)];
            if ([] !== $option->keys) {
                $spec['keys'] = array_map(
                    static fn (DictSubOption $sub): array => ['values' => $sub->values],
                    $option->keys,
                );
            }

            return $spec;
        }

        if ($option instanceof WeightsTemplateOption) {
            return ['type' => 'weights', 'values' => array_values(array_filter($option->validValues, is_string(...)))];
        }

        if ($option instanceof TextTemplateOption) {
            return ['type' => 'text', 'default' => $option->default];
        }

        // A type the vendored client does not model yet degrades to "unknown" rather than to a wrong
        // answer: the editor then falls back to its value-shape heuristic, which is what it did for
        // every option before this story.
        return ['type' => 'unknown'];
    }

    public function fetchLocationNames(string $hash): array
    {
        try {
            return $this->client->apworlds()->getLocations($hash);
        } catch (\Throwable $e) {
            $this->logger->warning('runner.apworld_locations_fetch_failed', ['hash' => $hash, 'error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * @return array{error: string, detail?: string}
     */
    private function classifyUploadError(string $message): array
    {
        if (!str_contains($message, 'generate_template exited 1:')) {
            return ['error' => 'runner_unavailable'];
        }

        if (str_contains($message, 'File is not a zip file')) {
            return ['error' => 'invalid_file'];
        }

        if (str_contains($message, 'No game registered from the apworld')) {
            return ['error' => 'template_failed', 'detail' => 'Cet apworld ne contient pas de jeu Archipelago (apworld client-only ou tracker sans composant serveur).'];
        }

        $detail = null;
        $lines = preg_split('/\r?\n/', $message) ?: [];

        // Prefer the exception message from our own warning line:
        // "Warning: failed to load <file> (<pkg>): <ExceptionType: message>"
        foreach ($lines as $line) {
            if (1 === preg_match('/^Warning: failed to load .+\((.+)\): (.+)$/', trim($line), $m)) {
                $detail = $m[2];
                break;
            }
        }

        // Fallback: last line that looks like a Python exception (ExceptionType: message)
        if (null === $detail) {
            foreach (array_reverse($lines) as $line) {
                $line = trim($line);
                if (1 === preg_match('/^[A-Z][A-Za-z]+Error: .+/', $line) || 1 === preg_match('/^[A-Z][A-Za-z]+Exception: .+/', $line)) {
                    $detail = $line;
                    break;
                }
            }
        }

        return null !== $detail
            ? ['error' => 'template_failed', 'detail' => $detail]
            : ['error' => 'template_failed'];
    }

    public function fetchApworldPreflights(): array
    {
        try {
            $verdicts = [];
            foreach ($this->client->apworlds()->list() as $entry) {
                // Apworlds uploaded before the preflight existed have no verdict: surface
                // them with an empty status so the backfill can find them (never blocking).
                $verdicts[$entry->hash] = null !== $entry->preflight
                    ? $this->preflightPayload($entry->preflight)
                    : ['status' => '', 'error' => '', 'checkedAt' => '', 'overridden' => false, 'blocks' => false];
            }

            return $verdicts;
        } catch (\Throwable $e) {
            $this->logger->warning('runner.apworld_preflights_fetch_failed', ['error' => $e->getMessage()]);

            return [];
        }
    }

    public function runApworldPreflight(string $hash): bool
    {
        try {
            $this->client->apworlds()->runPreflight($hash);

            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('runner.apworld_preflight_rerun_failed', ['hash' => $hash, 'error' => $e->getMessage()]);

            return false;
        }
    }

    public function overrideApworldPreflight(string $hash, bool $overridden): ?array
    {
        try {
            return $this->preflightPayload($this->client->apworlds()->overridePreflight($hash, $overridden));
        } catch (\Throwable $e) {
            $this->logger->warning('runner.apworld_preflight_override_failed', ['hash' => $hash, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @return array{status: string, error: string, checkedAt: string, overridden: bool, blocks: bool}
     */
    private function preflightPayload(ApworldPreflight $preflight): array
    {
        return [
            'status' => $preflight->status,
            'error' => $preflight->error,
            'checkedAt' => $preflight->checkedAt,
            'overridden' => $preflight->overridden,
            'blocks' => $preflight->blocksUsage(),
        ];
    }

    public function setApworldTemplate(string $hash, string $template): bool
    {
        try {
            $this->client->apworlds()->setYamlTemplate($hash, $template);

            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('runner.apworld_template_write_failed', ['hash' => $hash, 'error' => $e->getMessage()]);

            return false;
        }
    }

    public function regenerateApworldTemplate(string $hash): array
    {
        try {
            return ['template' => $this->client->apworlds()->regenerateYamlTemplate($hash)];
        } catch (\Throwable $e) {
            $this->logger->warning('runner.apworld_template_regenerate_failed', ['hash' => $hash, 'error' => $e->getMessage()]);

            return ['error' => $e->getMessage()];
        }
    }

    public function startSlotPreflight(string $playerYaml, ?string $apworldHash): ?string
    {
        try {
            return $this->client->preflight()->start($playerYaml, $apworldHash)->id;
        } catch (\Throwable $e) {
            $this->logger->warning('runner.slot_preflight_start_failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    public function getSlotPreflight(string $jobId): ?array
    {
        try {
            $job = $this->client->preflight()->get($jobId);

            return ['status' => $job->status, 'error' => $job->error];
        } catch (\Throwable $e) {
            $this->logger->warning('runner.slot_preflight_poll_failed', ['jobId' => $jobId, 'error' => $e->getMessage()]);

            return null;
        }
    }

    public function configureSession(string $sessionId, array $slots): array
    {
        try {
            $configureSlots = [];
            foreach ($slots as $slot) {
                $configureSlots[] = ConfigureSlot::fromYaml($slot['apworldHash'], $this->buildPlayerYaml($slot['slotName'], $slot['playerYaml']));
            }

            $result = $this->client->sessions()->configure($sessionId, new ConfigureRequest($configureSlots));

            $errors = [];
            foreach ($result->slots as $slotResult) {
                if ([] !== $slotResult->errors) {
                    $errors[] = ['playerName' => $slotResult->playerName, 'errors' => array_values($slotResult->errors)];
                }
            }

            return ['valid' => $result->valid, 'errors' => $errors];
        } catch (\Throwable $e) {
            $this->logger->error('runner.configure_failed', ['sessionId' => $sessionId, 'error' => $e->getMessage()]);

            return ['valid' => false, 'errors' => [['playerName' => '', 'errors' => ['Orchestrateur indisponible: '.$e->getMessage()]]]];
        }
    }

    /**
     * @param array<string, mixed> $generationOptions
     */
    public function generateSession(string $sessionId, string $adminPassword, ?string $seed = null, array $generationOptions = []): void
    {
        $this->client->sessions()->generate($sessionId, $adminPassword, $seed, $generationOptions);
    }

    /**
     * @param array<string, scalar> $serverOptions
     */
    public function launchSession(string $sessionId, string $adminPassword, ?string $serverPassword, array $serverOptions = []): void
    {
        $this->client->sessions()->launch($sessionId, $adminPassword, $serverPassword, $serverOptions);
    }

    public function stopSession(string $sessionId): void
    {
        try {
            $this->client->sessions()->stop($sessionId);
        } catch (\Throwable $e) {
            $this->logger->warning('runner.stop_failed', ['sessionId' => $sessionId, 'error' => $e->getMessage()]);
        }
    }

    public function restartSession(string $sessionId): void
    {
        $this->client->sessions()->restart($sessionId);
    }

    public function relaunchFromSave(string $sessionId): void
    {
        $this->client->sessions()->relaunchFromSave($sessionId);
    }

    public function getSessionInfo(string $sessionId): ?array
    {
        try {
            $response = $this->client->sessions()->get($sessionId);

            return ['status' => $response->status, 'bridgePort' => $response->bridgePort, 'apPort' => $response->apPort];
        } catch (\Archilan\OrchestratorClient\Exception\SessionNotFoundException) {
            return null;
        } catch (\Throwable $e) {
            $this->logger->warning('runner.get_session_info_failed', ['sessionId' => $sessionId, 'error' => $e->getMessage()]);

            return null;
        }
    }

    private function buildPlayerYaml(string $slotName, string $rawYaml): PlayerYaml
    {
        // Strip a leading UTF-8 BOM before parsing: Symfony's YAML parser throws on it
        // ("Mapping values are not allowed in multi-line blocks at line 1"), which used to
        // be swallowed below and produce a player YAML with an empty game - the AP generator
        // then fails with "No world found to handle game ." and the run never launches.
        if (str_starts_with($rawYaml, "\u{FEFF}")) {
            $rawYaml = substr($rawYaml, 3);
        }

        try {
            $parsed = Yaml::parse($rawYaml);
        } catch (ParseException $e) {
            $this->logger->error('runner.player_yaml_parse_failed', [
                'slotName' => $slotName,
                'error' => $e->getMessage(),
            ]);
            $parsed = [];
        }

        if (!is_array($parsed)) {
            $parsed = [];
        }

        $game = is_string($parsed['game'] ?? null) ? $parsed['game'] : '';

        // A player YAML with no resolvable game can never generate; fail loudly at configure
        // time (the caller marks the session failed) instead of staging a broken `game: ''`.
        if ('' === $game) {
            throw new \RuntimeException(sprintf('Player YAML for slot "%s" has no resolvable game.', $slotName));
        }

        $gameSection = is_array($parsed[$game] ?? null) ? $parsed[$game] : [];

        $options = [];
        foreach ($gameSection as $key => $value) {
            if (is_string($key)) {
                $options[] = new RawOptionValue($key, $value);
            }
        }

        return new PlayerYaml($slotName, $game, $options);
    }
}
