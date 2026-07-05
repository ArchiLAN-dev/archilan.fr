<?php

declare(strict_types=1);

namespace App\Shared\Application\Support;

use App\Shared\Infrastructure\Adapter\MinioStorageInterface;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\Support\RequiresAuthTrait;
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
        'Sessions',
        'PersonalRuns',
        'CatalogSync',
        'Streaming',
        'Membership',
        'WeeklyRuns',
        'SessionConfig',
        'Community',
    ];

    /** @var list<string> */
    private const LAYERS = ['Domain', 'Application', 'Infrastructure', 'Presentation'];

    /** @var list<string> */
    private const STARTER_PLACEHOLDERS = ['Controller', 'Entity', 'Repository'];

    /** @var list<string> */
    private const FORBIDDEN_APPLICATION_IMPORTS = [
        'Doctrine\\DBAL\\Connection',
        'Doctrine\\ORM\\EntityManagerInterface',
        'App\\Shared\\Application\\EntityFinderTrait',
    ];

    /** @var list<string> */
    private const FORBIDDEN_PRESENTATION_IMPORTS = [
        'Doctrine\\DBAL\\Connection',
        'Doctrine\\ORM\\EntityManagerInterface',
    ];

    /** @var list<string> */
    private const FORBIDDEN_PRESENTATION_CALLS = [
        'fetchAllAssociative',
        'fetchOne',
        'executeQuery',
        'createQueryBuilder',
        'createQuery',
        'createNativeQuery',
        'getRepository',
    ];

    /** @var list<string> */
    private const FORBIDDEN_APPLICATION_CLOCK_CALLS = [
        'date',
        'time',
        'rand',
        'mt_rand',
    ];

    /**
     * Named allowlist for intentional rule exceptions (story 33.5): the only sanctioned
     * way to except a file from a rule - never suppress via inline annotations.
     *
     * Sessions is frozen until Epic 32 merges; its two runner-callback handlers inject
     * the concrete RunnerCallbackClient (Sessions Infrastructure). TODO epic-32: extract
     * an Application-layer port for the runner callback client and drop these entries.
     *
     * @var list<string>
     */
    private const ALLOWED_APPLICATION_INFRASTRUCTURE_IMPORTS = [
        'Sessions/Application/Handler/ArchiveRunJobHandler.php',
        'Sessions/Application/Handler/FetchLogsJobHandler.php',
    ];

    /**
     * Contexts not yet migrated to the layer sub-folder taxonomy (story 33.10:
     * Domain/Exception, Application/Command|Query|Exception). Shrink this list as
     * contexts migrate - NEVER grow it. Sessions stays until Epic 32 merges
     * (TODO epic-32). Legal is absent: it has no PHP files, trivially compliant.
     *
     * @var list<string>
     */
    private const UNMIGRATED_TAXONOMY_CONTEXTS = [
        'Sessions',
    ];

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
            ...$this->validateCrossContextLayerImports($srcDir),
            ...$this->validateInterfacePlacement($srcDir),
            ...$this->validateApplicationCqrs($srcDir),
            ...$this->validateApplicationPurity($srcDir),
            ...$this->validatePresentationCqrs($srcDir),
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
            if (!is_dir("{$srcDir}/{$context}")) {
                $violations[] = "Missing bounded context directory: src/{$context}";
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

            if (in_array($relativePath, ['Kernel.php', 'Schedule.php'], true)) {
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

                foreach ($this->forbiddenDomainDependencies() as $dependency) {
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
     * A context may depend on another context's Domain or Application layer, but never on
     * its Infrastructure or Presentation layer. Shared is the shared kernel and is exempt
     * as a target (ApiAccessGuard, MinioStorageInterface, RequiresAuthTrait are documented
     * cross-cutting patterns).
     *
     * @return list<string>
     */
    private function validateCrossContextLayerImports(string $srcDir): array
    {
        $violations = [];

        foreach (self::CONTEXTS as $context) {
            $contextDir = "{$srcDir}/{$context}";
            if (!is_dir($contextDir)) {
                continue;
            }

            foreach ($this->phpFiles($contextDir) as $file) {
                $contents = file_get_contents($file);
                if (!is_string($contents)) {
                    continue;
                }

                $relativePath = $this->relativePath($srcDir, $file);

                foreach (self::CONTEXTS as $other) {
                    if ($other === $context || 'Shared' === $other) {
                        continue;
                    }

                    foreach (['Infrastructure', 'Presentation'] as $layer) {
                        $needle = "App\\{$other}\\{$layer}\\";
                        if (str_contains($contents, $needle)) {
                            $violations[] = sprintf(
                                'Cross-context dependency on another context\'s %s layer ("%s"): src/%s',
                                $layer,
                                $needle,
                                $relativePath,
                            );
                        }
                    }
                }
            }
        }

        return $violations;
    }

    /**
     * Repository interfaces belong to the Domain layer, query interfaces to the
     * Application layer (api/CLAUDE.md AC-A2). Contexts migrated to the story-33.10
     * taxonomy additionally require Application/Query/ for query interfaces and
     * {Layer}/Exception/ for Domain and Application exceptions (Infrastructure
     * exceptions deliberately stay in place).
     *
     * @return list<string>
     */
    private function validateInterfacePlacement(string $srcDir): array
    {
        $violations = [];

        foreach ($this->phpFiles($srcDir) as $file) {
            $relativePath = $this->relativePath($srcDir, $file);

            if (in_array($relativePath, ['Kernel.php', 'Schedule.php'], true)) {
                continue;
            }

            $parts = explode('/', $relativePath);
            if (!in_array($parts[0], self::CONTEXTS, true)) {
                continue;
            }

            $layer = $parts[1] ?? null;
            $basename = basename($relativePath);
            $migrated = !in_array($parts[0], self::UNMIGRATED_TAXONOMY_CONTEXTS, true);

            if (str_ends_with($basename, 'RepositoryInterface.php') && 'Domain' !== $layer) {
                $violations[] = "Repository interfaces must live in the Domain layer: src/{$relativePath}";
            }

            if (str_ends_with($basename, 'QueryInterface.php')) {
                if ($migrated && !str_starts_with($relativePath, $parts[0].'/Application/Query/')) {
                    $violations[] = "Query interfaces must live in Application/Query/ (taxonomy-migrated context): src/{$relativePath}";
                } elseif ('Application' !== $layer) {
                    $violations[] = "Query interfaces must live in the Application layer: src/{$relativePath}";
                }
            }

            if ($migrated && str_ends_with($basename, 'Exception.php') && in_array($layer, ['Domain', 'Application'], true)) {
                $expected = $parts[0].'/'.$layer.'/Exception/';
                if (!str_starts_with($relativePath, $expected)) {
                    $violations[] = "Exceptions must live in {$layer}/Exception/ (taxonomy-migrated context): src/{$relativePath}";
                }
            }
        }

        return $violations;
    }

    /**
     * Application services depend on ports (interfaces in Application, Domain or Shared),
     * never on Infrastructure classes (api/CLAUDE.md AC-A5, AC-I2), never instantiate
     * Infrastructure, and never read the clock directly (inject a clock or pass the value
     * as a parameter).
     *
     * @return list<string>
     */
    private function validateApplicationPurity(string $srcDir): array
    {
        $violations = [];

        foreach (self::CONTEXTS as $context) {
            $applicationDir = "{$srcDir}/{$context}/Application";
            if (!is_dir($applicationDir)) {
                continue;
            }

            foreach ($this->phpFiles($applicationDir) as $file) {
                $contents = file_get_contents($file);
                if (!is_string($contents)) {
                    continue;
                }

                $relativePath = $this->relativePath($srcDir, $file);
                $allowlisted = in_array($relativePath, self::ALLOWED_APPLICATION_INFRASTRUCTURE_IMPORTS, true);

                foreach (self::CONTEXTS as $other) {
                    if ('Shared' === $other) {
                        continue;
                    }

                    $needle = "App\\{$other}\\Infrastructure\\";

                    if (!$allowlisted && str_contains($contents, $needle)) {
                        $violations[] = sprintf(
                            'Application layer must not depend on the Infrastructure layer ("%s"): src/%s',
                            $needle,
                            $relativePath,
                        );
                    }

                    if (1 === preg_match('/new\s+\\\\?'.preg_quote($needle, '/').'/', $contents)) {
                        $violations[] = sprintf(
                            'Application layer must not instantiate Infrastructure classes ("new %s..."): src/%s',
                            $needle,
                            $relativePath,
                        );
                    }
                }

                foreach (self::FORBIDDEN_APPLICATION_CLOCK_CALLS as $function) {
                    if (1 === preg_match('/(?<![a-zA-Z0-9_$>:])'.preg_quote($function, '/').'\\(/', $contents)) {
                        $violations[] = sprintf(
                            'Application layer must not call %s() - inject a clock or pass the value as a parameter: src/%s',
                            $function,
                            $relativePath,
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
    private function validateApplicationCqrs(string $srcDir): array
    {
        $violations = [];

        foreach (self::CONTEXTS as $context) {
            $applicationDir = "{$srcDir}/{$context}/Application";
            if (!is_dir($applicationDir)) {
                continue;
            }

            foreach ($this->phpFiles($applicationDir) as $file) {
                $contents = file_get_contents($file);
                if (!is_string($contents)) {
                    continue;
                }

                $relativePath = $this->relativePath($srcDir, $file);

                foreach (self::FORBIDDEN_APPLICATION_IMPORTS as $import) {
                    if (str_contains($contents, $import)) {
                        $violations[] = "Application layer must not inject DB infrastructure ({$import}): src/{$relativePath}";
                    }
                }
            }
        }

        return $violations;
    }

    /**
     * @return list<string>
     */
    private function validatePresentationCqrs(string $srcDir): array
    {
        $violations = [];

        foreach (self::CONTEXTS as $context) {
            $presentationDir = "{$srcDir}/{$context}/Presentation";
            if (!is_dir($presentationDir)) {
                continue;
            }

            foreach ($this->phpFiles($presentationDir) as $file) {
                $contents = file_get_contents($file);
                if (!is_string($contents)) {
                    continue;
                }

                $relativePath = $this->relativePath($srcDir, $file);

                foreach (self::FORBIDDEN_PRESENTATION_IMPORTS as $import) {
                    if (str_contains($contents, $import)) {
                        $violations[] = "Presentation layer must not inject DB infrastructure ({$import}): src/{$relativePath}";
                    }
                }

                foreach (self::FORBIDDEN_PRESENTATION_CALLS as $method) {
                    if (1 === preg_match('/(?:->|::)'.preg_quote($method, '/').'\\s*\\(/', $contents)) {
                        $violations[] = "Presentation layer must not execute queries directly ({$method}): src/{$relativePath}";
                    }
                }
            }
        }

        return $violations;
    }

    /**
     * The Domain layer may not import an upper layer of ANY context (api/CLAUDE.md AC-D2),
     * nor the Symfony components below. Domain-to-Domain references across contexts are
     * allowed (repository interface signatures legitimately reference other aggregates).
     *
     * @return list<string>
     */
    private function forbiddenDomainDependencies(): array
    {
        $dependencies = [
            'Symfony\\Component\\Console\\',
            'Symfony\\Component\\DependencyInjection\\',
            'Symfony\\Component\\HttpFoundation\\',
            'Symfony\\Component\\Routing\\',
        ];

        foreach (self::CONTEXTS as $context) {
            foreach (['Application', 'Infrastructure', 'Presentation'] as $layer) {
                $dependencies[] = "App\\{$context}\\{$layer}\\";
            }
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
