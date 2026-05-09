<?php

declare(strict_types=1);

namespace App\Shared\Application;

use Symfony\Component\Yaml\Yaml;

final readonly class DddArchitectureValidator
{
    /** @var list<string> */
    private const CONTEXTS = [
        'Shared',
        'Identity',
        'Events',
        'Registrations',
        'GameSelection',
        'Content',
        'Payments',
        'Realtime',
        'Communications',
        'Legal',
    ];

    /** @var list<string> */
    private const LAYERS = ['Domain', 'Application', 'Infrastructure', 'Presentation'];

    /** @var list<string> */
    private const STARTER_PLACEHOLDERS = ['Controller', 'Entity', 'Repository'];

    public function validate(string $projectDir): DddArchitectureReport
    {
        $projectDir = rtrim(str_replace('\\', '/', $projectDir), '/');
        $srcDir = $projectDir.'/src';
        $violations = [];

        if (!is_dir($srcDir)) {
            return new DddArchitectureReport(["Missing source directory: {$srcDir}"]);
        }

        $violations = [
            ...$this->validateContextDirectories($srcDir),
            ...$this->validateSourceFiles($srcDir),
            ...$this->validateDomainDependencies($srcDir),
            ...$this->validateServicesConfig($projectDir),
            ...$this->validateDoctrineMappings($projectDir, $srcDir),
        ];

        return new DddArchitectureReport($violations);
    }

    /**
     * @return list<string>
     */
    private function validateContextDirectories(string $srcDir): array
    {
        $violations = [];

        foreach (self::CONTEXTS as $context) {
            $contextDir = "{$srcDir}/{$context}";
            if (!is_dir($contextDir)) {
                $violations[] = "Missing bounded context directory: src/{$context}";
                continue;
            }

            foreach (self::LAYERS as $layer) {
                if (!is_dir("{$contextDir}/{$layer}")) {
                    $violations[] = "Missing DDD layer: src/{$context}/{$layer}";
                }
            }
        }

        return $violations;
    }

    /**
     * @return list<string>
     */
    private function validateSourceFiles(string $srcDir): array
    {
        $violations = [];

        foreach ($this->phpFiles($srcDir) as $file) {
            $relativePath = $this->relativePath($srcDir, $file);

            if ('Kernel.php' === $relativePath) {
                continue;
            }

            $parts = explode('/', $relativePath);
            $topLevel = $parts[0];

            if (in_array($topLevel, self::STARTER_PLACEHOLDERS, true)) {
                $violations[] = "Starter placeholder contains PHP code: src/{$relativePath}";
                continue;
            }

            if (!in_array($topLevel, self::CONTEXTS, true)) {
                $violations[] = "PHP file is outside a bounded context: src/{$relativePath}";
                continue;
            }

            $layer = $parts[1] ?? null;
            if (!is_string($layer) || !in_array($layer, self::LAYERS, true)) {
                $violations[] = "PHP file is outside a DDD layer: src/{$relativePath}";
            }
        }

        return $violations;
    }

    /**
     * @return list<string>
     */
    private function validateDomainDependencies(string $srcDir): array
    {
        $violations = [];

        foreach (self::CONTEXTS as $context) {
            $domainDir = "{$srcDir}/{$context}/Domain";
            if (!is_dir($domainDir)) {
                continue;
            }

            foreach ($this->phpFiles($domainDir) as $file) {
                $contents = file_get_contents($file);
                if (!is_string($contents)) {
                    $violations[] = 'Unable to read domain file: src/'.$this->relativePath($srcDir, $file);
                    continue;
                }

                foreach ($this->forbiddenDomainDependencies($context) as $dependency) {
                    if (str_contains($contents, $dependency)) {
                        $violations[] = sprintf(
                            'Domain layer has forbidden dependency "%s": src/%s',
                            $dependency,
                            $this->relativePath($srcDir, $file),
                        );
                    }
                }
            }
        }

        return $violations;
    }

    /**
     * @return list<string>
     */
    private function validateServicesConfig(string $projectDir): array
    {
        $configPath = "{$projectDir}/config/services.yaml";
        if (!is_file($configPath)) {
            return ['Missing services config: config/services.yaml'];
        }

        $contents = file_get_contents($configPath);
        if (!is_string($contents)) {
            return ['Unable to read services config: config/services.yaml'];
        }

        $violations = [];
        foreach (self::CONTEXTS as $context) {
            $expected = "../src/{$context}/Domain/";
            if (!str_contains($contents, $expected)) {
                $violations[] = "Domain layer is not excluded from service autowiring: src/{$context}/Domain";
            }
        }

        return $violations;
    }

    /**
     * @return list<string>
     */
    private function validateDoctrineMappings(string $projectDir, string $srcDir): array
    {
        $configPath = "{$projectDir}/config/packages/doctrine.yaml";
        if (!is_file($configPath)) {
            return ['Missing Doctrine config: config/packages/doctrine.yaml'];
        }

        $config = Yaml::parseFile($configPath);
        if (!is_array($config)) {
            return ['Doctrine config must be a YAML mapping: config/packages/doctrine.yaml'];
        }

        $doctrine = $config['doctrine'] ?? null;
        if (!is_array($doctrine)) {
            return ['Doctrine config is missing the doctrine section: config/packages/doctrine.yaml'];
        }

        $orm = $doctrine['orm'] ?? null;
        if (!is_array($orm)) {
            return ['Doctrine config is missing the doctrine.orm section: config/packages/doctrine.yaml'];
        }

        $mappings = $orm['mappings'] ?? null;
        if (!is_array($mappings)) {
            return ['Doctrine ORM mappings are missing from config/packages/doctrine.yaml'];
        }

        $violations = [];
        foreach (self::CONTEXTS as $context) {
            if (!$this->domainContainsDoctrineEntity("{$srcDir}/{$context}/Domain")) {
                continue;
            }

            $mapping = $mappings[$context] ?? null;
            if (!is_array($mapping)) {
                $violations[] = "Missing Doctrine mapping for context with entities: {$context}";
                continue;
            }

            $expectedPrefix = "App\\{$context}\\Domain";
            if (($mapping['prefix'] ?? null) !== $expectedPrefix) {
                $violations[] = "Doctrine mapping {$context} must use prefix {$expectedPrefix}";
            }
        }

        return $violations;
    }

    /**
     * @return list<string>
     */
    private function forbiddenDomainDependencies(string $context): array
    {
        $dependencies = [
            'Symfony\\Component\\Console\\',
            'Symfony\\Component\\DependencyInjection\\',
            'Symfony\\Component\\HttpFoundation\\',
            'Symfony\\Component\\Routing\\',
        ];

        foreach (['Application', 'Infrastructure', 'Presentation'] as $layer) {
            $dependencies[] = "App\\{$context}\\{$layer}\\";
        }

        return $dependencies;
    }

    private function domainContainsDoctrineEntity(string $domainDir): bool
    {
        if (!is_dir($domainDir)) {
            return false;
        }

        foreach ($this->phpFiles($domainDir) as $file) {
            $contents = file_get_contents($file);
            if (is_string($contents) && str_contains($contents, '#[ORM\\Entity')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return \Generator<string>
     */
    private function phpFiles(string $directory): \Generator
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo instanceof \SplFileInfo || !$fileInfo->isFile()) {
                continue;
            }

            if ('php' === $fileInfo->getExtension()) {
                yield str_replace('\\', '/', $fileInfo->getPathname());
            }
        }
    }

    private function relativePath(string $baseDir, string $file): string
    {
        return ltrim(substr(str_replace('\\', '/', $file), strlen(rtrim($baseDir, '/'))), '/');
    }
}
