<?php

declare(strict_types=1);

namespace App\Sessions\Infrastructure;

use App\Sessions\Application\RunnerGatewayInterface;
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
            ];
        } catch (\Throwable $e) {
            $this->logger->error('runner.apworld_upload_failed', ['filename' => $filename, 'error' => $e->getMessage()]);

            return $this->classifyUploadError($e->getMessage());
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
            if (preg_match('/^Warning: failed to load .+\((.+)\): (.+)$/', trim($line), $m)) {
                $detail = $m[2];
                break;
            }
        }

        // Fallback: last line that looks like a Python exception (ExceptionType: message)
        if (null === $detail) {
            foreach (array_reverse($lines) as $line) {
                $line = trim($line);
                if (preg_match('/^[A-Z][A-Za-z]+Error: .+/', $line) || preg_match('/^[A-Z][A-Za-z]+Exception: .+/', $line)) {
                    $detail = $line;
                    break;
                }
            }
        }

        return null !== $detail
            ? ['error' => 'template_failed', 'detail' => $detail]
            : ['error' => 'template_failed'];
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
    public function launchSession(string $sessionId, string $adminPassword, string $serverPassword, array $serverOptions = []): void
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
        try {
            $parsed = Yaml::parse($rawYaml);
        } catch (ParseException) {
            $parsed = [];
        }

        if (!is_array($parsed)) {
            $parsed = [];
        }

        $game = is_string($parsed['game'] ?? null) ? $parsed['game'] : '';

        $gameSection = ('' !== $game && is_array($parsed[$game] ?? null)) ? $parsed[$game] : [];

        $options = [];
        foreach ($gameSection as $key => $value) {
            if (is_string($key)) {
                $options[] = new RawOptionValue($key, $value);
            }
        }

        return new PlayerYaml($slotName, $game, $options);
    }
}
