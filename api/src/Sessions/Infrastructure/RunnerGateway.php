<?php

declare(strict_types=1);

namespace App\Sessions\Infrastructure;

use Archilan\OrchestratorClient\OrchestratorClient;
use Archilan\OrchestratorClient\Sessions\Request\PreflightRequest;
use Archilan\OrchestratorClient\Sessions\Request\PreflightSlot;
use Archilan\OrchestratorClient\Sessions\Response\PreflightSlotResult;
use Psr\Log\LoggerInterface;

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

            return ['error' => 'runner_unavailable'];
        }
    }
}
