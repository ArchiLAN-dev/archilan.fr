<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Infrastructure;

use App\Shared\Infrastructure\DockerSocketClient;
use App\Shared\Infrastructure\MinioStorageInterface;
use App\WeeklyRuns\Application\WeeklyRunGeneratorInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class DockerWeeklyRunGenerator implements WeeklyRunGeneratorInterface
{
    public function __construct(
        private DockerSocketClient $dockerClient,
        private MinioStorageInterface $minioStorage,
        private HttpClientInterface $httpClient,
        private string $generateImage,
        private string $workspaceDir,
        private string $minioApworldsBucket,
        private int $minioPresignTtl,
    ) {
    }

    public function generate(
        string $weeklyRunId,
        string $apworldStorageKey,
        string $templateYaml,
        string $seed,
    ): string {
        $sessionDir = $this->workspaceDir.'/'.$weeklyRunId;
        $apworldsDir = $sessionDir.'/apworlds';
        $yamlDir = $sessionDir.'/yamls';
        $outputDir = $sessionDir.'/output';

        foreach ([$apworldsDir, $yamlDir, $outputDir] as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }

        $this->downloadApworld($apworldStorageKey, $apworldsDir);

        $yamlFile = $yamlDir.'/ArchiLAN.yaml';
        file_put_contents($yamlFile, $templateYaml);

        $cmd = [
            '--player_files_path', '/yamls',
            '--outputpath', '/output',
            '--multi', '1',
            '--seed', $seed,
        ];

        $binds = [
            $yamlDir.':/yamls',
            $outputDir.':/output',
            $apworldsDir.':/apworlds',
        ];

        $result = $this->dockerClient->runEphemeral(
            image: $this->generateImage,
            entrypoint: 'python3',
            cmd: ['/usr/local/bin/generate_multiworld.py', '--world_directory', '/apworlds', ...$cmd],
            binds: $binds,
        );

        if (0 !== $result['exitCode']) {
            throw new \RuntimeException('Archipelago generation failed (exit '.$result['exitCode'].'): '.$result['output']);
        }

        $archipelagoFiles = array_merge(
            glob($outputDir.'/*.archipelago') ?: [],
            glob($outputDir.'/*.zip') ?: [],
        );

        if ([] === $archipelagoFiles) {
            throw new \RuntimeException('Archipelago generation produced no seed file. Output: '.$result['output']);
        }

        $seedFile = $archipelagoFiles[0];

        if ('zip' === strtolower(pathinfo($seedFile, \PATHINFO_EXTENSION))) {
            $this->removeSpoilerFromZip($seedFile);
        }

        return $seedFile;
    }

    private function downloadApworld(string $storageKey, string $destDir): void
    {
        $presignedUrl = $this->minioStorage->presignedUrl(
            $this->minioApworldsBucket,
            $storageKey,
            $this->minioPresignTtl,
        );

        $content = $this->httpClient->request('GET', $presignedUrl)->getContent(true);
        file_put_contents($destDir.'/'.basename($storageKey), $content);
    }

    private function removeSpoilerFromZip(string $zipPath): void
    {
        $zip = new \ZipArchive();
        if (true !== $zip->open($zipPath)) {
            return;
        }

        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $name = $zip->getNameIndex($i);
            if (false === $name) {
                continue;
            }
            if (str_contains(strtolower(basename($name)), '_spoiler')) {
                $zip->deleteIndex($i);
            }
        }

        $zip->close();
    }
}
