<?php

declare(strict_types=1);

namespace App\Sessions\Infrastructure\Adapter;

use App\Sessions\Application\Port\SeedArchiveGatewayInterface;
use App\Shared\Infrastructure\Adapter\MinioStorageInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Story 16.18. Talks to the orchestrator directly rather than through the generated client: both
 * calls are multipart uploads that the client package does not model, and the weekly-run gateway
 * already set the precedent for reaching the orchestrator this way.
 */
final readonly class OrchestratorSeedArchiveGateway implements SeedArchiveGatewayInterface
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private MinioStorageInterface $minioStorage,
        private LoggerInterface $logger,
        private string $orchestrateurBaseUrl,
        private string $orchestrateurApiKey,
        private string $minioSessionsBucket,
    ) {
    }

    public function inspect(string $archive, string $filename): array
    {
        try {
            $form = new FormDataPart(['file' => new DataPart($archive, $filename, 'application/octet-stream')]);
            $response = $this->httpClient->request('POST', $this->url('/multidata/inspect'), [
                'headers' => $form->getPreparedHeaders()->toArray() + ['Authorization' => 'Bearer '.$this->orchestrateurApiKey],
                'body' => $form->bodyToIterable(),
            ]);

            /** @var array<string, mixed> $payload */
            $payload = $response->toArray(false);
        } catch (\Throwable $e) {
            $this->logger->error('seed_import.inspect_failed', ['error' => $e->getMessage()]);

            return ['error' => 'runner_unavailable'];
        }

        $error = $payload['error'] ?? null;
        if (is_string($error) && '' !== $error) {
            return ['error' => $error];
        }

        $rawSlots = $payload['slots'] ?? null;
        if (!is_array($rawSlots) || [] === $rawSlots) {
            return ['error' => 'no_slots'];
        }

        $slots = [];
        foreach ($rawSlots as $rawSlot) {
            if (!is_array($rawSlot)) {
                continue;
            }
            $number = $rawSlot['slot'] ?? null;
            $name = $rawSlot['name'] ?? null;
            if (!is_int($number) || !is_string($name) || '' === $name) {
                continue;
            }
            $game = $rawSlot['game'] ?? null;
            $type = $rawSlot['type'] ?? null;
            $slots[] = [
                'slot' => $number,
                'name' => $name,
                'game' => is_string($game) ? $game : '',
                'type' => is_int($type) ? $type : 1,
            ];
        }

        if ([] === $slots) {
            return ['error' => 'no_slots'];
        }

        $seedName = $payload['seedName'] ?? null;

        return ['slots' => $slots, 'seedName' => is_string($seedName) ? $seedName : ''];
    }

    public function launchFromArchive(
        string $sessionId,
        string $outputKey,
        string $adminPassword,
        ?string $serverPassword,
        array $slotNames,
        array $serverOptions = [],
    ): void {
        $archive = $this->minioStorage->download($this->minioSessionsBucket, $outputKey);

        $fields = [
            'adminPassword' => $adminPassword,
            'serverPassword' => $serverPassword ?? '',
            // The bridge has no observer slot to attach to on an imported seed, so it is told the
            // roster instead of assuming one is waiting for it.
            'slotNames' => json_encode($slotNames, \JSON_THROW_ON_ERROR),
        ];
        foreach ($serverOptions as $key => $value) {
            $fields[$key] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        }
        $fields['file'] = new DataPart($archive, basename($outputKey), 'application/zip');

        $form = new FormDataPart($fields);
        $response = $this->httpClient->request('POST', $this->url('/sessions/'.$sessionId.'/launch-from-file'), [
            'headers' => $form->getPreparedHeaders()->toArray() + ['Authorization' => 'Bearer '.$this->orchestrateurApiKey],
            'body' => $form->bodyToIterable(),
        ]);

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException(sprintf('Launch from archive failed (HTTP %d): %s', $status, $this->safeBody($response)));
        }

        $this->logger->info('seed_import.launch_from_archive', ['sessionId' => $sessionId, 'outputKey' => $outputKey]);
    }

    private function url(string $path): string
    {
        return rtrim($this->orchestrateurBaseUrl, '/').$path;
    }

    private function safeBody(ResponseInterface $response): string
    {
        try {
            return $response->getContent(false);
        } catch (\Throwable) {
            return '';
        }
    }
}
