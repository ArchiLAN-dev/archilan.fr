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
    private const array CONTEXTS = [
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
    private const array LAYERS = ['Domain', 'Application', 'Infrastructure', 'Presentation'];

    /** @var list<string> */
    private const array STARTER_PLACEHOLDERS = ['Controller', 'Entity', 'Repository'];

    /** @var list<string> */
    private const array FORBIDDEN_APPLICATION_IMPORTS = [
        'Doctrine\\DBAL\\Connection',
        'Doctrine\\ORM\\EntityManagerInterface',
        'App\\Shared\\Application\\EntityFinderTrait',
    ];

    /** @var list<string> */
    private const array FORBIDDEN_PRESENTATION_IMPORTS = [
        'Doctrine\\DBAL\\Connection',
        'Doctrine\\ORM\\EntityManagerInterface',
    ];

    /** @var list<string> */
    private const array FORBIDDEN_PRESENTATION_CALLS = [
        'fetchAllAssociative',
        'fetchOne',
        'executeQuery',
        'createQueryBuilder',
        'createQuery',
        'createNativeQuery',
        'getRepository',
    ];

    /** @var list<string> */
    private const array FORBIDDEN_APPLICATION_CLOCK_CALLS = [
        'date',
        'time',
        'rand',
        'mt_rand',
    ];

    /**
     * A DateTimeImmutable/DateTime constructed with no argument (or the literal 'now')
     * reads the wall clock, the same class of impurity as the forbidden clock calls above
     * (story 33.15). Application code must inject Psr\Clock\ClockInterface and call
     * $this->clock->now() instead. Only the argument-less (or explicit 'now') form is a
     * clock read; passing an ISO string or a variable parses a specific instant and is allowed.
     *
     * @var list<string>
     */
    private const array FORBIDDEN_APPLICATION_CLOCK_CONSTRUCTS = [
        'DateTimeImmutable',
        'DateTime',
    ];

    // CLOCK_CONSTRUCT_EXEMPT_CONTEXTS (story 33.15) is GONE. Its only entry was the frozen
    // Sessions context; 33.20 injected Psr\Clock\ClockInterface into its 6 Application classes
    // (16 call sites), which left the exemption branch as dead code. The Application layer may
    // not read the wall clock by construction anywhere, with no escape hatch.

    /**
     * The only sanctioned Symfony imports in a Domain layer (api/CLAUDE.md AC-D1, story 33.17):
     * the security contracts the framework requires on the user entity. Keyed by file, values are
     * the exact import FQCNs stripped before the forbidden-dependency scan. Anything else Symfony
     * in Domain is a violation - extend this list only with the same kind of framework-mandated
     * contract, never for convenience.
     *
     * @var array<string, list<string>>
     */
    private const array ALLOWED_DOMAIN_SYMFONY_IMPORTS = [
        'Identity/Domain/Entity/User.php' => [
            'Symfony\\Component\\Security\\Core\\User\\PasswordAuthenticatedUserInterface',
            'Symfony\\Component\\Security\\Core\\User\\UserInterface',
        ],
    ];

    // FINALITY_EXEMPT_CONTEXTS (story 33.17) is GONE. Its only entry was the frozen Sessions
    // context; 33.20 made its four entities final, which left the exemption branch as dead
    // code. The finality rule (AC-D4) now applies to every context, unconditionally.

    /**
     * The only sanctioned non-final class in an Application layer (api/CLAUDE.md AC-A1,
     * story 33.17): the abstract email template base - inheritance is its mechanism and its
     * subclasses are final. Application SERVICES stay final; extend this list only for
     * template-method style bases of the same kind, never for services.
     *
     * @var list<string>
     */
    private const array ALLOWED_APPLICATION_NON_FINAL = [
        'Communications/Application/Email/ArchilanEmail.php',
    ];

    /**
     * The only Application classes allowed to declare a Domain entity return type
     * (api/CLAUDE.md AC-A3, story 33.17): the auth resolvers feeding Symfony security wiring,
     * which by nature hand the User aggregate to the framework - they are not read-model
     * queries. Everything else returns DTOs, records or arrays; entities never cross into
     * Presentation.
     *
     * @var list<string>
     */
    private const array ALLOWED_APPLICATION_ENTITY_RETURNS = [
        'Identity/Application/Service/AuthenticateUser.php',
        'Identity/Application/Service/CurrentUserProvider.php',
    ];

    /**
     * Contexts exempt from the no-public-setters rule (api/CLAUDE.md AC-D5, story 33.16)
     * because their aggregates still expose set-prefixed mutators. Sessions is frozen until
     * Epic 32 merges (TODO epic-32, follow-up 33.20); once migrated, empty this list -
     * never grow it.
     *
     * @var list<string>
     */
    private const array AGGREGATE_SETTER_EXEMPT_CONTEXTS = [
        'Sessions',
    ];

    // The Application->Infrastructure allowlist (story 33.5) is GONE, not merely emptied.
    // Its last two entries were the Sessions runner-callback handlers injecting the concrete
    // RunnerCallbackClient; story 33.20 extracted RunnerCallbackClientInterface
    // (Sessions/Application/Port) and dropped them. With the list empty the allowlist branch
    // was provably dead code (phpstan: `in_array($x, [])` is always false), so the mechanism
    // went with it. The rule now holds unconditionally: no Application file anywhere imports
    // Infrastructure. If a future exception is ever genuinely warranted, re-introduce the
    // allowlist deliberately - do not suppress inline (api/CLAUDE.md).

    // UNMIGRATED_TAXONOMY_CONTEXTS (stories 33.10/33.11) is GONE. It shrank 17 -> 1 -> 0:
    // Sessions was the last holdout, frozen for Epic 32, and 33.20 migrated it. The taxonomy
    // (no flat layer files; Application/Query/ for query interfaces; {Layer}/Exception/ for
    // exceptions; Domain/Entity/ for the Doctrine prefix) is now enforced for EVERY context
    // with no escape hatch. The decreasing-allowlist model did its job and retired.

    /**
     * Full sub-folder taxonomy carve-out (story 33.11): these 4 Community domain classes are
     * imported by MERGED migrations (immutable), so they keep their flat FQCN and are exempt
     * from the "no .php directly in a layer folder" rule. See migrations/Version20260618170000
     * and Version20260622120000.
     *
     * @var list<string>
     */
    private const array FLAT_FILE_CARVE_OUT = [
        'Community/Domain/DefaultAchievementDefinitions.php',
        'Community/Domain/AchievementMetricCatalog.php',
        'Community/Domain/AchievementOperator.php',
        'Community/Domain/AchievementRuleGroup.php',
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
            ...$this->validateNoFlatLayerFiles($srcDir),
            ...$this->validateDomainDependencies($srcDir),
            ...$this->validateMembershipGating($srcDir),
            ...$this->validateDomainFinality($srcDir),
            ...$this->validateApplicationFinality($srcDir),
            ...$this->validateApplicationEntityReturns($srcDir),
            ...$this->validateDomainAggregateSetters($srcDir),
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
     * Full sub-folder taxonomy (story 33.11): in a migrated context, no .php file may sit directly
     * in a layer folder - it must live in a kind sub-folder (Entity/, ValueObject/, Command/,
     * Doctrine/, Controller/, ...). The 4 migration-pinned carve-outs are exempt. Sessions (frozen)
     * is skipped entirely.
     *
     * @return list<string>
     */
    private function validateNoFlatLayerFiles(string $srcDir): array
    {
        $violations = [];

        foreach ($this->phpFiles($srcDir) as $file) {
            $relativePath = $this->relativePath($srcDir, $file);
            $parts = explode('/', $relativePath);

            // interested only in exactly {Context}/{Layer}/{File}.php (3 segments = file directly
            // in a layer). A file in a sub-folder has 4+ segments and is correctly placed.
            if (3 !== count($parts)) {
                continue;
            }
            if (!in_array($parts[0], self::CONTEXTS, true) || !in_array($parts[1], self::LAYERS, true)) {
                continue;
            }
            if (in_array($relativePath, self::FLAT_FILE_CARVE_OUT, true)) {
                continue;
            }

            $violations[] = "No file may sit directly in a layer folder; move it into a kind sub-folder: src/{$relativePath}";
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

                $relativePath = $this->relativePath($srcDir, $file);
                foreach (self::ALLOWED_DOMAIN_SYMFONY_IMPORTS[$relativePath] ?? [] as $allowedImport) {
                    // Strip the exact import statement only - a bare-FQCN strip would erase the
                    // prefix of any longer, non-allowlisted FQCN and would sanction usages of
                    // the class name elsewhere in the file body.
                    $contents = str_replace('use '.$allowedImport.';', '', $contents);
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
     * Aggregates are final classes, value objects are final readonly classes (api/CLAUDE.md
     * AC-D4, story 33.17). The rule scans the taxonomy sub-folders Domain/Entity/ and
     * Domain/ValueObject/ - frozen Sessions has neither until 33.20 migrates it, at which point
     * the rule applies automatically. Interfaces, traits and enums are not class declarations
     * and pass untouched (enums are implicitly final).
     *
     * @return list<string>
     */
    private function validateDomainFinality(string $srcDir): array
    {
        $violations = [];

        foreach (self::CONTEXTS as $context) {
            foreach (['Entity' => 'final', 'ValueObject' => 'final readonly'] as $kind => $required) {
                $kindDir = "{$srcDir}/{$context}/Domain/{$kind}";
                if (!is_dir($kindDir)) {
                    continue;
                }

                foreach ($this->phpFiles($kindDir) as $file) {
                    $contents = file_get_contents($file);
                    if (!is_string($contents)) {
                        continue;
                    }

                    if (preg_match_all('/^[ \t]*((?:(?:abstract|final|readonly)[ \t]+)*)class[ \t]+\w+/m', $contents, $matches, PREG_SET_ORDER) > 0) {
                        foreach ($matches as $match) {
                            $hasFinal = str_contains($match[1], 'final');
                            $hasReadonly = str_contains($match[1], 'readonly');
                            if ($hasFinal && ('final' === $required || $hasReadonly)) {
                                continue;
                            }

                            $violations[] = sprintf(
                                'Domain %s classes must be declared "%s class" (api/CLAUDE.md AC-D4): src/%s',
                                $kind,
                                $required,
                                $this->relativePath($srcDir, $file),
                            );
                        }
                    }
                }
            }
        }

        return $violations;
    }

    /**
     * Application classes are final - no extension, no inheritance hierarchies (api/CLAUDE.md
     * AC-A1, story 33.17). Interfaces, traits and enums pass untouched; the named allowlist
     * holds the sanctioned template-method bases.
     *
     * @return list<string>
     */
    private function validateApplicationFinality(string $srcDir): array
    {
        $violations = [];

        foreach (self::CONTEXTS as $context) {
            $applicationDir = "{$srcDir}/{$context}/Application";
            if (!is_dir($applicationDir)) {
                continue;
            }

            foreach ($this->phpFiles($applicationDir) as $file) {
                $relativePath = $this->relativePath($srcDir, $file);
                if (in_array($relativePath, self::ALLOWED_APPLICATION_NON_FINAL, true)) {
                    continue;
                }

                $contents = file_get_contents($file);
                if (!is_string($contents)) {
                    continue;
                }

                if (preg_match_all('/^[ \t]*((?:(?:abstract|final|readonly)[ \t]+)*)class[ \t]+\w+/m', $contents, $matches, PREG_SET_ORDER) > 0) {
                    foreach ($matches as $match) {
                        if (!str_contains($match[1], 'final')) {
                            $violations[] = sprintf(
                                'Application classes must be final (api/CLAUDE.md AC-A1): src/%s',
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
     * No public Application method declares a Domain entity return type (api/CLAUDE.md AC-A3,
     * story 33.17): commands return void or result records/arrays, queries return DTOs - raw
     * Doctrine entities never cross into Presentation. Lexical check: entity imports
     * (aliased or not) are collected per file, then every declared return type is tokenized
     * (nullable stripped, unions split) and compared; fully-qualified entity return types are
     * caught by their Domain-entity namespace segment.
     *
     * @return list<string>
     */
    private function validateApplicationEntityReturns(string $srcDir): array
    {
        $violations = [];

        foreach (self::CONTEXTS as $context) {
            $applicationDir = "{$srcDir}/{$context}/Application";
            if (!is_dir($applicationDir)) {
                continue;
            }

            foreach ($this->phpFiles($applicationDir) as $file) {
                $relativePath = $this->relativePath($srcDir, $file);
                if (in_array($relativePath, self::ALLOWED_APPLICATION_ENTITY_RETURNS, true)) {
                    continue;
                }

                $contents = file_get_contents($file);
                if (!is_string($contents)) {
                    continue;
                }

                $entityNames = [];
                if (preg_match_all('/^use\s+App\\\\\w+\\\\Domain\\\\Entity\\\\(?:\w+\\\\)*(\w+)(?:\s+as\s+(\w+))?\s*;/m', $contents, $imports, PREG_SET_ORDER) > 0) {
                    foreach ($imports as $import) {
                        $entityNames[] = $import[2] ?? $import[1];
                    }
                }

                // Named function declarations with their (explicit or implied-public) visibility,
                // by offset - each return type is attributed to the nearest preceding declaration,
                // so private/protected helpers may hand entities around inside the layer.
                $declarations = [];
                if (preg_match_all('/(?:(?:static|final|abstract)\s+)*(?:(private|protected|public)\s+)?(?:(?:static|final|abstract)\s+)*function\s+&?\s*\w+\s*\(/', $contents, $decls, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) > 0) {
                    foreach ($decls as $decl) {
                        $declarations[$decl[0][1]] = '' !== ($decl[1][0] ?? '') ? $decl[1][0] : 'public';
                    }
                }

                // No whitespace tolerated between ")" and ":" - cs-fixer normalizes real return
                // types to "): T", while a ternary else-branch ("... ) : User::guest()") always
                // carries the space and must not register as a return type.
                if (0 === preg_match_all('/\):\s*([?\w|\\\\]+)/', $contents, $returns, PREG_OFFSET_CAPTURE)) {
                    continue;
                }

                foreach ($returns[1] as $return) {
                    $visibility = 'public';
                    foreach ($declarations as $offset => $declVisibility) {
                        if ($offset > $return[1]) {
                            break;
                        }
                        $visibility = $declVisibility;
                    }

                    if ('public' !== $visibility) {
                        continue;
                    }

                    foreach (explode('|', ltrim($return[0], '?')) as $token) {
                        $token = ltrim($token, '?');
                        if (in_array($token, $entityNames, true) || str_contains($token, 'Domain\\Entity\\')) {
                            $violations[] = sprintf(
                                'Application methods must not return Domain entities ("%s") - return a DTO, record or array instead (api/CLAUDE.md AC-A3): src/%s',
                                $return[0],
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
     * ROLE_MEMBER is stale-prone (it survives membership expiry) and must never gate access
     * (api/CLAUDE.md AC-M1, story 33.17). This rule forbids the gating call forms (positional or
     * attribute-named argument) combining the grant checkers below with that role. The bare role
     * string in this method cannot self-match: the pattern requires the full checker-call shape,
     * which never appears here contiguously. Display/filter/assignment reads of the role
     * (AC-M3: user directory, Discord sync, role mapping) use in_array and are NOT matched;
     * variable/constant-form gating and security expressions in YAML are beyond a lexical scan
     * (accepted limitation, same class as the deferred tokenizer work).
     *
     * @return list<string>
     */
    private function validateMembershipGating(string $srcDir): array
    {
        $violations = [];
        $checkers = ['denyAccessUnlessGranted', 'isGranted', 'IsGranted'];
        $role = 'ROLE_MEMBER';
        $pattern = sprintf('/(?:%s)\s*\(\s*(?:attribute\s*:\s*)?[\'"]%s[\'"]/', implode('|', $checkers), $role);

        foreach ($this->phpFiles($srcDir) as $file) {
            $contents = file_get_contents($file);
            if (!is_string($contents)) {
                continue;
            }

            if (preg_match_all($pattern, $contents, $matches) > 0) {
                foreach ($matches[0] as $match) {
                    $violations[] = sprintf(
                        'Access must never be gated on the stale %s role ("%s") - use ApiAccessGuard::requireAuthenticatedMember() or the IS_MEMBER voter (api/CLAUDE.md AC-M1): src/%s',
                        $role,
                        preg_replace('/\s+/', ' ', $match) ?? $match,
                        $this->relativePath($srcDir, $file),
                    );
                }
            }
        }

        return $violations;
    }

    /**
     * State changes on Domain aggregates happen through named business methods, never through
     * public set-prefixed mutators (api/CLAUDE.md AC-D5, story 33.16). The rule matches the
     * declaration form only; the method body is irrelevant - a mutator with logic is still a
     * mutator whose name hides the intent.
     *
     * @return list<string>
     */
    private function validateDomainAggregateSetters(string $srcDir): array
    {
        $violations = [];

        foreach (self::CONTEXTS as $context) {
            if (in_array($context, self::AGGREGATE_SETTER_EXEMPT_CONTEXTS, true)) {
                continue;
            }

            $domainDir = "{$srcDir}/{$context}/Domain";
            if (!is_dir($domainDir)) {
                continue;
            }

            foreach ($this->phpFiles($domainDir) as $file) {
                $contents = file_get_contents($file);
                if (!is_string($contents)) {
                    continue;
                }

                if (preg_match_all('/public\s+(?:(?:static|final|abstract)\s+)*function\s+&?\s*set[A-Z]\w*/', $contents, $matches) > 0) {
                    foreach ($matches[0] as $match) {
                        $violations[] = sprintf(
                            'Domain layer must not expose public setters ("%s") - replace with a named business method (api/CLAUDE.md AC-D5): src/%s',
                            preg_replace('/\s+/', ' ', $match) ?? $match,
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

            if (str_ends_with($basename, 'RepositoryInterface.php') && 'Domain' !== $layer) {
                $violations[] = "Repository interfaces must live in the Domain layer: src/{$relativePath}";
            }

            if (str_ends_with($basename, 'QueryInterface.php')
                && !str_starts_with($relativePath, $parts[0].'/Application/Query/')
            ) {
                $violations[] = "Query interfaces must live in Application/Query/: src/{$relativePath}";
            }

            if (str_ends_with($basename, 'Exception.php') && in_array($layer, ['Domain', 'Application'], true)) {
                $expected = $parts[0].'/'.$layer.'/Exception/';
                if (!str_starts_with($relativePath, $expected)) {
                    $violations[] = "Exceptions must live in {$layer}/Exception/: src/{$relativePath}";
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

                foreach (self::CONTEXTS as $other) {
                    if ('Shared' === $other) {
                        continue;
                    }

                    $needle = "App\\{$other}\\Infrastructure\\";

                    if (str_contains($contents, $needle)) {
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

                foreach (self::FORBIDDEN_APPLICATION_CLOCK_CONSTRUCTS as $class) {
                    // Zero-arg or explicit 'now' only - argumented constructions parse a specific instant.
                    $pattern = '/new\s+\\\\?'.preg_quote($class, '/').'\s*\(\s*(?:\'now\'|"now")?\s*\)/';

                    if (1 === preg_match($pattern, $contents)) {
                        $violations[] = sprintf(
                            'Application layer must not construct %s() to read the wall clock - inject Psr\Clock\ClockInterface and call $this->clock->now(): src/%s',
                            $class,
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

            // Every context keeps its entities in Domain/Entity/ (story 33.11, and Sessions
            // since 33.20), so the mapping prefix always carries the \Entity segment.
            $expectedPrefix = "App\\{$context}\\Domain\\Entity";
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
            'Symfony\\Bridge\\',
            'Symfony\\Bundle\\',
            'Symfony\\Component\\',
            'Symfony\\Contracts\\',
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
