<?php

declare(strict_types=1);

namespace App\Sessions\Application\Handler;

use App\Sessions\Infrastructure\DockerSocketClient;
use App\Sessions\Infrastructure\RunnerCallbackClient;
use App\Shared\Application\Message\GenerateRunJob;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsMessageHandler]
final readonly class GenerateRunJobHandler
{
    public function __construct(
        private RunnerCallbackClient $callbackClient,
        private DockerSocketClient $dockerClient,
        private LoggerInterface $logger,
        private HttpClientInterface $httpClient,
        private string $runnerId,
        private string $workspaceDir,
        private string $generateImage = 'archipelago-generate',
    ) {
    }

    public function __invoke(GenerateRunJob $job): void
    {
        $this->logger->info('runner.generate_job.received', [
            'session_id' => $job->sessionId,
            'runner_id' => $this->runnerId,
            'phase' => $job->phase,
        ]);

        if ('validate' === $job->phase) {
            $this->runValidate($job);

            return;
        }

        if ('generate' === $job->phase) {
            $this->runGenerate($job);

            return;
        }

        $this->logger->warning('runner.generate_job.unknown_phase', [
            'session_id' => $job->sessionId,
            'phase' => $job->phase,
        ]);
    }

    private function runValidate(GenerateRunJob $job): void
    {
        $errors = [];
        $seenNames = [];

        foreach ($job->slots as $slot) {
            $slotErrors = [];
            $slotName = $slot['slotName'];

            if (mb_strlen($slotName) > 16) {
                $slotErrors[] = sprintf('Le nom de slot "%s" depasse 16 caracteres.', $slotName);
            }

            if (in_array($slotName, $seenNames, true)) {
                $slotErrors[] = sprintf('Le nom de slot "%s" n\'est pas unique dans la session.', $slotName);
            } else {
                $seenNames[] = $slotName;
            }

            if ('' === trim($slot['archipelagoGameName'])) {
                $slotErrors[] = 'Le nom de jeu Archipelago est manquant.';
            }

            if ('' === trim($slot['playerYaml'])) {
                $slotErrors[] = 'Le YAML du joueur est manquant.';
            }

            if ([] !== $slotErrors) {
                $errors[] = ['slotName' => $slotName, 'errors' => $slotErrors];
            }
        }

        if ([] !== $errors) {
            $this->callbackClient->sendCallback($job->sessionId, [
                'status' => 'draft',
                'errors' => $errors,
            ]);
            $this->logger->warning('runner.validate_job.failed', [
                'session_id' => $job->sessionId,
                'error_count' => count($errors),
                'errors' => $errors,
            ]);

            return;
        }

        $yamlDir = $this->workspaceDir.'/'.$job->sessionId.'/yamls';
        if (!is_dir($yamlDir)) {
            mkdir($yamlDir, 0755, true);
        }

        foreach ($job->slots as $slot) {
            $yaml = preg_replace('/^name:\s*.+$/m', 'name: '.$slot['slotName'], $slot['playerYaml'], 1);
            file_put_contents($yamlDir.'/'.$slot['slotName'].'.yaml', $yaml ?? $slot['playerYaml']);
        }

        $this->callbackClient->sendCallback($job->sessionId, ['status' => 'ready']);

        $this->logger->info('runner.validate_job.succeeded', [
            'session_id' => $job->sessionId,
            'slot_count' => count($job->slots),
        ]);
    }

    private function runGenerate(GenerateRunJob $job): void
    {
        $yamlDir = $this->workspaceDir.'/'.$job->sessionId.'/yamls';
        $outputDir = $this->workspaceDir.'/'.$job->sessionId.'/output';

        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $yamlCount = count(glob($yamlDir.'/*.yaml') ?: []);

        $cmd = ['--player_files_path', '/yamls', '--outputpath', '/output', '--multi', (string) $yamlCount];

        if (null !== $job->seed && '' !== $job->seed) {
            $cmd[] = '--seed';
            $cmd[] = $job->seed;
        }

        $binds = [$yamlDir.':/yamls', $outputDir.':/output'];

        if ([] !== $job->apworldDownloadUrls) {
            $sessionApworldsDir = $this->workspaceDir.'/'.$job->sessionId.'/apworlds';
            if (!is_dir($sessionApworldsDir)) {
                mkdir($sessionApworldsDir, 0755, true);
            }

            if (!$this->downloadApworlds($job, $sessionApworldsDir)) {
                return;
            }

            $binds[] = $sessionApworldsDir.':/apworlds';
            $cmd[] = '--world_directory';
            $cmd[] = '/apworlds';
        }

        try {
            $result = $this->dockerClient->runEphemeral(
                image: $this->generateImage,
                entrypoint: 'python3',
                cmd: ['/usr/local/bin/generate_multiworld.py', ...$cmd],
                binds: $binds,
            );
        } catch (\Throwable $e) {
            $this->callbackClient->sendCallback($job->sessionId, [
                'status' => 'failed',
                'errors' => ['Impossible de demarrer le container de generation : '.$e->getMessage()],
            ]);
            $this->logger->error('runner.generate_job.docker_error', [
                'session_id' => $job->sessionId,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        if (0 !== $result['exitCode']) {
            $this->callbackClient->sendCallback($job->sessionId, [
                'status' => 'failed',
                'logs' => '' !== $result['output'] ? $result['output'] : 'La generation a echoue sans message d\'erreur.',
            ]);
            $this->logger->error('runner.generate_job.failed', [
                'session_id' => $job->sessionId,
                'exit_code' => $result['exitCode'],
            ]);

            return;
        }

        $archipelagoFiles = array_merge(
            glob($outputDir.'/*.archipelago') ?: [],
            glob($outputDir.'/*.zip') ?: [],
        );

        if ([] === $archipelagoFiles) {
            $this->callbackClient->sendCallback($job->sessionId, [
                'status' => 'failed',
                'errors' => ['Aucun fichier de seed produit par la generation.'],
            ]);
            $this->logger->error('runner.generate_job.no_output', ['session_id' => $job->sessionId]);

            return;
        }

        $this->callbackClient->sendCallback($job->sessionId, ['status' => 'generated']);
        $this->logger->info('runner.generate_job.succeeded', [
            'session_id' => $job->sessionId,
            'output_file' => basename($archipelagoFiles[0]),
        ]);
    }

    private function downloadApworlds(GenerateRunJob $job, string $sessionApworldsDir): bool
    {
        foreach ($job->apworldDownloadUrls as $key => $url) {
            try {
                $response = $this->httpClient->request('GET', $url);
                $content = $response->getContent(true);
                file_put_contents($sessionApworldsDir.'/'.$key, $content);
            } catch (\Throwable $e) {
                $this->callbackClient->sendCallback($job->sessionId, [
                    'status' => 'failed',
                    'errors' => ['Impossible de telecharger l\'APWorld depuis MinIO : '.$e->getMessage()],
                ]);
                $this->logger->error('runner.generate_job.apworld_download_error', [
                    'session_id' => $job->sessionId,
                    'key' => $key,
                    'error' => $e->getMessage(),
                ]);

                return false;
            }
        }

        return true;
    }
}
