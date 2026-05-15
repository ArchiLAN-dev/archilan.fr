<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Shared\Application\DddArchitectureValidator;
use PHPUnit\Framework\TestCase;

final class DddArchitectureValidatorTest extends TestCase
{
    private ?string $projectDir = null;

    protected function tearDown(): void
    {
        if (null !== $this->projectDir && is_dir($this->projectDir)) {
            $this->removeDirectory($this->projectDir);
        }
    }

    public function testValidProjectStructurePasses(): void
    {
        $projectDir = $this->createProjectFixture();

        $report = (new DddArchitectureValidator())->validate($projectDir);

        self::assertTrue($report->isSuccessful(), implode("\n", $report->violations()));
        self::assertSame([], $report->violations());
    }

    public function testPhpFileOutsideDddLayerIsReported(): void
    {
        $projectDir = $this->createProjectFixture();
        file_put_contents($projectDir.'/src/Events/EventHelper.php', "<?php\n");

        $report = (new DddArchitectureValidator())->validate($projectDir);

        self::assertFalse($report->isSuccessful());
        self::assertContains('PHP file is outside a DDD layer: src/Events/EventHelper.php', $report->violations());
    }

    public function testDomainDependencyOnPresentationIsReported(): void
    {
        $projectDir = $this->createProjectFixture();
        file_put_contents(
            $projectDir.'/src/Events/Domain/BrokenRule.php',
            "<?php\n\nnamespace App\\Events\\Domain;\n\nuse App\\Events\\Presentation\\AdminEventController;\n",
        );

        $report = (new DddArchitectureValidator())->validate($projectDir);

        self::assertFalse($report->isSuccessful());
        self::assertContains(
            'Domain layer has forbidden dependency "App\\Events\\Presentation\\": src/Events/Domain/BrokenRule.php',
            $report->violations(),
        );
    }

    public function testPresentationWithForbiddenConnectionImportIsReported(): void
    {
        $projectDir = $this->createProjectFixture();
        file_put_contents(
            $projectDir.'/src/Events/Presentation/AdminEventController.php',
            "<?php\n\nnamespace App\\Events\\Presentation;\n\nuse Doctrine\\DBAL\\Connection;\n\nfinal class AdminEventController {}\n",
        );

        $report = (new DddArchitectureValidator())->validate($projectDir);

        self::assertFalse($report->isSuccessful());
        self::assertContains(
            'Presentation layer must not inject DB infrastructure (Doctrine\\DBAL\\Connection): src/Events/Presentation/AdminEventController.php',
            $report->violations(),
        );
    }

    public function testPresentationWithForbiddenEntityManagerImportIsReported(): void
    {
        $projectDir = $this->createProjectFixture();
        file_put_contents(
            $projectDir.'/src/Events/Presentation/AdminEventController.php',
            "<?php\n\nnamespace App\\Events\\Presentation;\n\nuse Doctrine\\ORM\\EntityManagerInterface;\n\nfinal class AdminEventController {}\n",
        );

        $report = (new DddArchitectureValidator())->validate($projectDir);

        self::assertFalse($report->isSuccessful());
        self::assertContains(
            'Presentation layer must not inject DB infrastructure (Doctrine\\ORM\\EntityManagerInterface): src/Events/Presentation/AdminEventController.php',
            $report->violations(),
        );
    }

    public function testPresentationWithForbiddenSqlMethodIsReported(): void
    {
        $projectDir = $this->createProjectFixture();
        file_put_contents(
            $projectDir.'/src/Events/Presentation/AdminEventController.php',
            "<?php\n\nnamespace App\\Events\\Presentation;\n\nfinal class AdminEventController {\n    public function __invoke(): void { \$this->conn->fetchAllAssociative('SELECT 1'); }\n}\n",
        );

        $report = (new DddArchitectureValidator())->validate($projectDir);

        self::assertFalse($report->isSuccessful());
        self::assertContains(
            'Presentation layer must not execute queries directly (fetchAllAssociative): src/Events/Presentation/AdminEventController.php',
            $report->violations(),
        );
    }

    public function testCreateQueryBuilderDoesNotTriggerCreateQueryViolation(): void
    {
        $projectDir = $this->createProjectFixture();
        file_put_contents(
            $projectDir.'/src/Events/Presentation/AdminEventController.php',
            "<?php\n\nnamespace App\\Events\\Presentation;\n\nfinal class AdminEventController {\n    public function __invoke(): void { \$this->em->createQueryBuilder()->select('e')->from('Event', 'e'); }\n}\n",
        );

        $report = (new DddArchitectureValidator())->validate($projectDir);

        $violations = $report->violations();

        self::assertContains(
            'Presentation layer must not execute queries directly (createQueryBuilder): src/Events/Presentation/AdminEventController.php',
            $violations,
            'createQueryBuilder must be reported',
        );
        self::assertNotContains(
            'Presentation layer must not execute queries directly (createQuery): src/Events/Presentation/AdminEventController.php',
            $violations,
            'createQuery must NOT be reported when only createQueryBuilder is present',
        );
    }

    public function testApplicationWithDbInfrastructureIsNotReported(): void
    {
        $projectDir = $this->createProjectFixture();
        file_put_contents(
            $projectDir.'/src/Events/Application/EventQuery.php',
            "<?php\n\nnamespace App\\Events\\Application;\n\nuse Doctrine\\DBAL\\Connection;\nuse Doctrine\\ORM\\EntityManagerInterface;\n\nfinal class EventQuery {\n    public function __construct(private Connection \$conn, private EntityManagerInterface \$em) {}\n    public function find(): array { return \$this->conn->fetchAllAssociative('SELECT 1'); }\n}\n",
        );

        $report = (new DddArchitectureValidator())->validate($projectDir);

        $cqrsViolationsForApplicationFile = array_values(array_filter(
            $report->violations(),
            static fn (string $v): bool => str_contains($v, 'EventQuery.php') && str_contains($v, 'Presentation layer'),
        ));
        self::assertCount(0, $cqrsViolationsForApplicationFile, 'Application layer DB imports must not trigger CQRS violations');
    }

    public function testCleanPresentationControllerIsNotReported(): void
    {
        $projectDir = $this->createProjectFixture();
        file_put_contents(
            $projectDir.'/src/Events/Presentation/AdminEventController.php',
            "<?php\n\nnamespace App\\Events\\Presentation;\n\nfinal class AdminEventController {\n    public function __construct(private object \$catalog) {}\n}\n",
        );

        $report = (new DddArchitectureValidator())->validate($projectDir);

        $cqrsViolations = array_filter(
            $report->violations(),
            static fn (string $v): bool => str_contains($v, 'AdminEventController.php'),
        );
        self::assertCount(0, array_values($cqrsViolations));
    }

    private function createProjectFixture(): string
    {
        $projectDir = sys_get_temp_dir().'/archilan-ddd-validator-'.bin2hex(random_bytes(6));
        $this->projectDir = $projectDir;

        $this->createDirectory($projectDir.'/config/packages');
        $this->createDirectory($projectDir.'/src');

        $contexts = [
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
        ];
        $layers = ['Domain', 'Application', 'Infrastructure', 'Presentation'];

        foreach ($contexts as $context) {
            foreach ($layers as $layer) {
                $this->createDirectory("{$projectDir}/src/{$context}/{$layer}");
            }
        }

        file_put_contents($projectDir.'/src/Events/Domain/Event.php', <<<'PHP'
<?php

namespace App\Events\Domain;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
final class Event
{
}
PHP);

        file_put_contents($projectDir.'/config/services.yaml', $this->servicesYaml($contexts));
        file_put_contents($projectDir.'/config/packages/doctrine.yaml', $this->doctrineYaml());

        return $projectDir;
    }

    /**
     * @param list<string> $contexts
     */
    private function servicesYaml(array $contexts): string
    {
        $excludes = array_map(
            static fn (string $context): string => "            - '../src/{$context}/Domain/'",
            $contexts,
        );

        return "services:\n"
            ."    App\\\\:\n"
            ."        resource: '../src/'\n"
            ."        exclude:\n"
            ."            - '../src/Kernel.php'\n"
            .implode("\n", $excludes)
            ."\n";
    }

    private function doctrineYaml(): string
    {
        return <<<'YAML'
doctrine:
    orm:
        mappings:
            Events:
                type: attribute
                is_bundle: false
                dir: '%kernel.project_dir%/src/Events/Domain'
                prefix: 'App\Events\Domain'
                alias: Events
YAML;
    }

    private function createDirectory(string $directory): void
    {
        if (!mkdir($directory, 0777, true) && !is_dir($directory)) {
            self::fail(sprintf('Unable to create fixture directory: %s', $directory));
        }
    }

    private function removeDirectory(string $directory): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            if (!$item instanceof \SplFileInfo) {
                continue;
            }

            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($directory);
    }
}
