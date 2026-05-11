<?php

declare(strict_types=1);

namespace App\Sessions\Application\Handler;

use App\Sessions\Application\Message\RunHealthCheckJob;
use App\Sessions\Application\Message\StartRunJob;
use App\Sessions\Infrastructure\DockerSocketClient;
use App\Sessions\Infrastructure\PortPool;
use App\Sessions\Infrastructure\RunnerCallbackClient;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

#[AsMessageHandler]
final readonly class StartRunJobHandler
{
    public function __construct(
        private RunnerCallbackClient $callbackClient,
        private DockerSocketClient $dockerClient,
        private PortPool $portPool,
        private MessageBusInterface $messageBus,
        private LoggerInterface $logger,
        private string $runnerId,
        private string $runnerHost,
        private string $workspaceDir,
        private string $centralApiSecret,
        private string $symfonyInternalUrl,
        private string $mercureHubUrl,
        private string $serverImage = 'archipelago-server',
        private string $apworldVolumeName = '',
    ) {
    }

    public function __invoke(StartRunJob $job): void
    {
        $this->logger->info('runner.start_job.received', [
            'session_id' => $job->sessionId,
            'runner_id' => $this->runnerId,
        ]);

        $reusingCredentials = null !== $job->existingPort && null !== $job->existingPassword;

        $seedFile = $this->extractSeed($job->sessionId);

        if (null === $seedFile) {
            $this->callbackClient->sendCallback($job->sessionId, [
                'status' => 'failed',
                'errors' => ['Fichier seed .archipelago introuvable dans l\'archive de génération.'],
            ]);
            $this->logger->error('runner.start_job.no_seed', ['session_id' => $job->sessionId]);

            return;
        }

        if ($reusingCredentials) {
            // Reuse existing ports - mark them allocated so the pool doesn't hand them out again.
            $this->portPool->markAllocated(array_values(array_filter([$job->existingPort, $job->existingBridgePort])));
            $port = $job->existingPort;
            $bridgePort = $job->existingBridgePort ?? $this->portPool->allocate() ?? 0;
            $password = $job->existingPassword;
            $adminPassword = $job->existingServerPassword ?? bin2hex(random_bytes(16));
        } else {
            $port = $this->portPool->allocate();
            if (null === $port) {
                $this->callbackClient->sendCallback($job->sessionId, [
                    'status' => 'failed',
                    'errors' => ['Aucun port disponible sur ce runner.'],
                ]);
                $this->logger->error('runner.start_job.no_port', ['session_id' => $job->sessionId]);

                return;
            }

            $bridgePort = $this->portPool->allocate();
            if (null === $bridgePort) {
                $this->portPool->release($port);
                $this->callbackClient->sendCallback($job->sessionId, [
                    'status' => 'failed',
                    'errors' => ['Aucun port bridge disponible sur ce runner.'],
                ]);
                $this->logger->error('runner.start_job.no_bridge_port', ['session_id' => $job->sessionId]);

                return;
            }

            $password = bin2hex(random_bytes(8));
            $adminPassword = bin2hex(random_bytes(16));
        }

        $seedDir = dirname($seedFile);
        $saveDir = $this->workspaceDir.'/'.$job->sessionId.'/saves';
        $yamlsDir = $this->workspaceDir.'/'.$job->sessionId.'/yamls';
        $apworldsDir = $this->workspaceDir.'/'.$job->sessionId.'/apworlds';
        $containerName = 'archipelago-run-'.$job->sessionId;
        $containerId = '';

        try {
            if (!is_dir($saveDir)) {
                mkdir($saveDir, 0755, true);
            }

            $binds = [
                $seedDir.':/archipelago/output',
                $saveDir.':/archipelago/saves',
                $yamlsDir.':/archipelago/yamls:ro',
            ];

            if ('' !== $this->apworldVolumeName) {
                // Dev mode: workspace volume already has all apworlds at /arch_workspace/apworlds
                $binds[] = $this->apworldVolumeName.':/arch_workspace:ro';
            } elseif (is_dir($apworldsDir)) {
                // Prod mode: session-specific apworlds copied by generate handler
                $binds[] = $apworldsDir.':/apworlds:ro';
            }

            $containerId = $this->dockerClient->startPersistent(
                name: $containerName,
                image: $this->serverImage,
                binds: $binds,
                env: [
                    'SEED_FILE' => '/archipelago/output/'.basename($seedFile),
                    'PASSWORD' => $password,
                    'SERVER_PASSWORD' => $adminPassword,
                    'RUN_ID' => $job->sessionId,
                    'CENTRAL_API_SECRET' => $this->centralApiSecret,
                    'SYMFONY_INTERNAL_URL' => $this->symfonyInternalUrl,
                    'MERCURE_HUB_URL' => $this->mercureHubUrl,
                    'SLOT_NAMES' => json_encode($this->readYamlPlayerNames($job->sessionId), JSON_THROW_ON_ERROR),
                ],
                ports: [
                    '38281/tcp' => $port,
                    '5000/tcp' => $bridgePort,
                ],
            );
        } catch (\Throwable $e) {
            // 409 Conflict: container already exists (e.g. was stopped, not removed).
            // If we have existing credentials, just start the existing container.
            if (str_contains($e->getMessage(), 'docker_conflict')) {
                if ($reusingCredentials) {
                    try {
                        $this->dockerClient->start($containerName);
                        $this->logger->info('runner.start_job.started_existing_container', ['session_id' => $job->sessionId]);
                    } catch (\Throwable $startEx) {
                        $this->callbackClient->sendCallback($job->sessionId, [
                            'status' => 'failed',
                            'errors' => ['Le container existe mais n\'a pas pu être démarré : '.$startEx->getMessage()],
                        ]);
                        $this->logger->error('runner.start_job.start_existing_failed', [
                            'session_id' => $job->sessionId,
                            'error' => $startEx->getMessage(),
                        ]);

                        return;
                    }
                // Fall through to send the callback below with reused credentials.
                } else {
                    // Duplicate message delivery - first dispatch already handled it.
                    $this->logger->warning('runner.start_job.container_already_exists', ['session_id' => $job->sessionId]);

                    return;
                }
            } else {
                $this->portPool->release($port);
                $this->portPool->release($bridgePort);
                $this->callbackClient->sendCallback($job->sessionId, [
                    'status' => 'failed',
                    'errors' => ['Impossible de démarrer le container Archipelago : '.$e->getMessage()],
                ]);
                $this->logger->error('runner.start_job.docker_failed', [
                    'session_id' => $job->sessionId,
                    'error' => $e->getMessage(),
                ]);

                return;
            }
        }

        $this->callbackClient->sendCallback($job->sessionId, [
            'status' => 'running',
            'host' => $this->runnerHost,
            'port' => $port,
            'bridge_port' => $bridgePort,
            'password' => $password,
            'server_password' => $adminPassword,
        ]);

        $this->messageBus->dispatch(
            new RunHealthCheckJob($job->sessionId, $port, $bridgePort, 0),
            [new DelayStamp(30000)],
        );

        $this->logger->info('runner.start_job.succeeded', [
            'session_id' => $job->sessionId,
            'port' => $port,
            'bridge_port' => $bridgePort,
            'container' => $containerId,
        ]);
    }

    /**
     * Reads the `name:` and `game:` fields from each YAML in the session's yaml directory.
     * Returns a sorted list of {name, game} pairs matching the actual Archipelago slot identifiers.
     *
     * @return list<array{name: string, game: string}>
     */
    private function readYamlPlayerNames(string $sessionId): array
    {
        $yamlDir = $this->workspaceDir.'/'.$sessionId.'/yamls';
        $slots = [];

        foreach (glob($yamlDir.'/*.yaml') ?: [] as $file) {
            $content = file_get_contents($file);
            if (false === $content) {
                continue;
            }

            $name = '';
            $game = '';

            if (preg_match('/^name:\s*[\'"]?(.+?)[\'"]?\s*$/m', $content, $m)) {
                $name = trim($m[1]);
            }
            if (preg_match('/^game:\s*[\'"]?(.+?)[\'"]?\s*$/m', $content, $m)) {
                $game = trim($m[1]);
            }

            if ('' !== $name && '' !== $game) {
                $slots[] = ['name' => $name, 'game' => $game];
            }
        }

        usort($slots, static fn (array $a, array $b) => strcmp($a['name'], $b['name']));

        return $slots;
    }

    /**
     * Finds the .zip in output/, extracts the .archipelago seed into seed/, and returns its path.
     */
    private function extractSeed(string $sessionId): ?string
    {
        $outputDir = $this->workspaceDir.'/'.$sessionId.'/output';
        $zipFiles = array_merge(
            glob($outputDir.'/*.zip') ?: [],
            glob($outputDir.'/*.archipelago') ?: [],
        );

        if ([] === $zipFiles) {
            return null;
        }

        $zipPath = $zipFiles[0];

        // If it's already a bare .archipelago file (older Archipelago versions), use it directly.
        if (str_ends_with($zipPath, '.archipelago')) {
            return $zipPath;
        }

        $seedDir = $this->workspaceDir.'/'.$sessionId.'/seed';
        if (!is_dir($seedDir)) {
            mkdir($seedDir, 0755, true);
        }

        $zip = new \ZipArchive();
        if (true !== $zip->open($zipPath)) {
            return null;
        }

        $extracted = null;
        for ($i = 0; $i < $zip->count(); ++$i) {
            $name = $zip->getNameIndex($i);
            if (is_string($name) && str_ends_with($name, '.archipelago')) {
                $zip->extractTo($seedDir, $name);
                $extracted = $seedDir.'/'.basename($name);
                break;
            }
        }

        $zip->close();

        return $extracted;
    }
}
