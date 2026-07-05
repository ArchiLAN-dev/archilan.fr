<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Shared\Application\Support\DddArchitectureValidator;
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

    public function testCrossContextInfrastructureImportIsReported(): void
    {
        $projectDir = $this->createProjectFixture();
        file_put_contents(
            $projectDir.'/src/Events/Application/SyncBridge.php',
            "<?php\n\nnamespace App\\Events\\Application;\n\nuse App\\Payments\\Infrastructure\\HelloAssoHttpClient;\n\nfinal class SyncBridge {}\n",
        );

        $report = (new DddArchitectureValidator())->validate($projectDir);

        self::assertFalse($report->isSuccessful());
        self::assertContains(
            'Cross-context dependency on another context\'s Infrastructure layer ("App\\Payments\\Infrastructure\\"): src/Events/Application/SyncBridge.php',
            $report->violations(),
        );
    }

    public function testCrossContextPresentationImportIsReported(): void
    {
        $projectDir = $this->createProjectFixture();
        file_put_contents(
            $projectDir.'/src/Events/Infrastructure/WeirdBridge.php',
            "<?php\n\nnamespace App\\Events\\Infrastructure;\n\nuse App\\Payments\\Presentation\\MembershipCheckoutController;\n\nfinal class WeirdBridge {}\n",
        );

        $report = (new DddArchitectureValidator())->validate($projectDir);

        self::assertFalse($report->isSuccessful());
        self::assertContains(
            'Cross-context dependency on another context\'s Presentation layer ("App\\Payments\\Presentation\\"): src/Events/Infrastructure/WeirdBridge.php',
            $report->violations(),
        );
    }

    public function testCrossContextDomainAndApplicationImportsAreAllowed(): void
    {
        $projectDir = $this->createProjectFixture();
        file_put_contents(
            $projectDir.'/src/Registrations/Application/ReserveSeat.php',
            "<?php\n\nnamespace App\\Registrations\\Application;\n\nuse App\\Events\\Domain\\Event;\nuse App\\Payments\\Application\\PaymentLookup;\n\nfinal class ReserveSeat {}\n",
        );

        $report = (new DddArchitectureValidator())->validate($projectDir);

        $violationsForFile = array_values(array_filter(
            $report->violations(),
            static fn (string $v): bool => str_contains($v, 'ReserveSeat.php'),
        ));
        self::assertSame([], $violationsForFile);
    }

    public function testSharedInfrastructureImportIsAllowedInApplication(): void
    {
        $projectDir = $this->createProjectFixture();
        file_put_contents(
            $projectDir.'/src/Events/Application/CoverImageReader.php',
            "<?php\n\nnamespace App\\Events\\Application;\n\nuse App\\Shared\\Infrastructure\\MinioStorageInterface;\n\nfinal class CoverImageReader {}\n",
        );

        $report = (new DddArchitectureValidator())->validate($projectDir);

        $violationsForFile = array_values(array_filter(
            $report->violations(),
            static fn (string $v): bool => str_contains($v, 'CoverImageReader.php'),
        ));
        self::assertSame([], $violationsForFile);
    }

    public function testDomainImportOfAnotherContextApplicationIsReported(): void
    {
        $projectDir = $this->createProjectFixture();
        file_put_contents(
            $projectDir.'/src/Events/Domain/CrossLayerLeak.php',
            "<?php\n\nnamespace App\\Events\\Domain;\n\nuse App\\Payments\\Application\\PaymentLookup;\n\nfinal class CrossLayerLeak {}\n",
        );

        $report = (new DddArchitectureValidator())->validate($projectDir);

        self::assertFalse($report->isSuccessful());
        self::assertContains(
            'Domain layer has forbidden dependency "App\\Payments\\Application\\": src/Events/Domain/CrossLayerLeak.php',
            $report->violations(),
        );
    }

    public function testRepositoryInterfaceOutsideDomainIsReported(): void
    {
        $projectDir = $this->createProjectFixture();
        file_put_contents(
            $projectDir.'/src/Events/Application/EventRepositoryInterface.php',
            "<?php\n\nnamespace App\\Events\\Application;\n\ninterface EventRepositoryInterface {}\n",
        );

        $report = (new DddArchitectureValidator())->validate($projectDir);

        self::assertFalse($report->isSuccessful());
        self::assertContains(
            'Repository interfaces must live in the Domain layer: src/Events/Application/EventRepositoryInterface.php',
            $report->violations(),
        );
    }

    public function testQueryInterfaceOutsideApplicationIsReported(): void
    {
        $projectDir = $this->createProjectFixture();
        // Sessions is unmigrated (frozen until Epic 32), so it uses the pre-taxonomy message.
        file_put_contents(
            $projectDir.'/src/Sessions/Infrastructure/DashboardQueryInterface.php',
            "<?php\n\nnamespace App\\Sessions\\Infrastructure;\n\ninterface DashboardQueryInterface {}\n",
        );

        $report = (new DddArchitectureValidator())->validate($projectDir);

        self::assertFalse($report->isSuccessful());
        self::assertContains(
            'Query interfaces must live in the Application layer: src/Sessions/Infrastructure/DashboardQueryInterface.php',
            $report->violations(),
        );
    }

    public function testApplicationOwnInfrastructureImportIsReported(): void
    {
        $projectDir = $this->createProjectFixture();
        file_put_contents(
            $projectDir.'/src/Events/Application/GalleryService.php',
            "<?php\n\nnamespace App\\Events\\Application;\n\nuse App\\Events\\Infrastructure\\GalleryHttpClient;\n\nfinal class GalleryService {}\n",
        );

        $report = (new DddArchitectureValidator())->validate($projectDir);

        self::assertFalse($report->isSuccessful());
        self::assertContains(
            'Application layer must not depend on the Infrastructure layer ("App\\Events\\Infrastructure\\"): src/Events/Application/GalleryService.php',
            $report->violations(),
        );
    }

    public function testAllowlistedApplicationInfrastructureImportPasses(): void
    {
        $projectDir = $this->createProjectFixture();
        $this->createDirectory($projectDir.'/src/Sessions/Application/Handler');
        file_put_contents(
            $projectDir.'/src/Sessions/Application/Handler/ArchiveRunJobHandler.php',
            "<?php\n\nnamespace App\\Sessions\\Application\\Handler;\n\nuse App\\Sessions\\Infrastructure\\RunnerCallbackClient;\n\nfinal class ArchiveRunJobHandler {}\n",
        );

        $report = (new DddArchitectureValidator())->validate($projectDir);

        $violationsForFile = array_values(array_filter(
            $report->violations(),
            static fn (string $v): bool => str_contains($v, 'ArchiveRunJobHandler.php'),
        ));
        self::assertSame([], $violationsForFile);
    }

    public function testApplicationNewOnInfrastructureIsReported(): void
    {
        $projectDir = $this->createProjectFixture();
        file_put_contents(
            $projectDir.'/src/Events/Application/InlineFactory.php',
            "<?php\n\nnamespace App\\Events\\Application;\n\nfinal class InlineFactory {\n    public function make(): object { return new \\App\\Events\\Infrastructure\\GalleryHttpClient(); }\n}\n",
        );

        $report = (new DddArchitectureValidator())->validate($projectDir);

        self::assertFalse($report->isSuccessful());
        self::assertContains(
            'Application layer must not instantiate Infrastructure classes ("new App\\Events\\Infrastructure\\..."): src/Events/Application/InlineFactory.php',
            $report->violations(),
        );
    }

    public function testApplicationClockCallIsReported(): void
    {
        $projectDir = $this->createProjectFixture();
        file_put_contents(
            $projectDir.'/src/Events/Application/ClockUser.php',
            "<?php\n\nnamespace App\\Events\\Application;\n\nfinal class ClockUser {\n    public function stamp(): string { return date('Y-m-d'); }\n}\n",
        );

        $report = (new DddArchitectureValidator())->validate($projectDir);

        self::assertFalse($report->isSuccessful());
        self::assertContains(
            'Application layer must not call date() - inject a clock or pass the value as a parameter: src/Events/Application/ClockUser.php',
            $report->violations(),
        );
    }

    public function testApplicationClockLookalikesAreNotReported(): void
    {
        $projectDir = $this->createProjectFixture();
        file_put_contents(
            $projectDir.'/src/Events/Application/NotAClock.php',
            "<?php\n\nnamespace App\\Events\\Application;\n\nfinal class NotAClock {\n"
            ."    public function run(object \$repo, string \$raw, \\DateTimeImmutable \$now): void {\n"
            ."        \$repo->update(\$raw);\n"
            ."        \$parsed = new \\DateTimeImmutable(\$raw);\n"
            ."        \$ts = strtotime(\$raw);\n"
            ."        \$repo->time(\$parsed, \$ts, \$now);\n"
            ."    }\n"
            ."}\n",
        );

        $report = (new DddArchitectureValidator())->validate($projectDir);

        $violationsForFile = array_values(array_filter(
            $report->violations(),
            static fn (string $v): bool => str_contains($v, 'NotAClock.php'),
        ));
        self::assertSame([], $violationsForFile);
    }

    public function testTaxonomyExceptionOutsideExceptionFolderIsReportedForMigratedContext(): void
    {
        $projectDir = $this->createProjectFixture();
        file_put_contents(
            $projectDir.'/src/Legal/Domain/ConsentMissingException.php',
            "<?php\n\nnamespace App\\Legal\\Domain;\n\nfinal class ConsentMissingException extends \\RuntimeException {}\n",
        );
        file_put_contents(
            $projectDir.'/src/Legal/Application/ExportFailedException.php',
            "<?php\n\nnamespace App\\Legal\\Application;\n\nfinal class ExportFailedException extends \\RuntimeException {}\n",
        );

        $report = (new DddArchitectureValidator())->validate($projectDir);

        self::assertFalse($report->isSuccessful());
        self::assertContains(
            'Exceptions must live in Domain/Exception/ (taxonomy-migrated context): src/Legal/Domain/ConsentMissingException.php',
            $report->violations(),
        );
        self::assertContains(
            'Exceptions must live in Application/Exception/ (taxonomy-migrated context): src/Legal/Application/ExportFailedException.php',
            $report->violations(),
        );
    }

    public function testTaxonomyCompliantMigratedContextPasses(): void
    {
        $projectDir = $this->createProjectFixture();
        $this->createDirectory($projectDir.'/src/Legal/Domain/Exception');
        $this->createDirectory($projectDir.'/src/Legal/Application/Query');
        file_put_contents(
            $projectDir.'/src/Legal/Domain/Exception/ConsentMissingException.php',
            "<?php\n\nnamespace App\\Legal\\Domain\\Exception;\n\nfinal class ConsentMissingException extends \\RuntimeException {}\n",
        );
        file_put_contents(
            $projectDir.'/src/Legal/Application/Query/ConsentLogQueryInterface.php',
            "<?php\n\nnamespace App\\Legal\\Application\\Query;\n\ninterface ConsentLogQueryInterface {}\n",
        );

        $report = (new DddArchitectureValidator())->validate($projectDir);

        $violationsForContext = array_values(array_filter(
            $report->violations(),
            static fn (string $v): bool => str_contains($v, 'src/Legal/'),
        ));
        self::assertSame([], $violationsForContext);
    }

    public function testTaxonomyRulesAreNotAppliedToUnmigratedContext(): void
    {
        $projectDir = $this->createProjectFixture();
        // Sessions is unmigrated (frozen until Epic 32): taxonomy rules must not fire for it.
        file_put_contents(
            $projectDir.'/src/Sessions/Domain/CapacityExceededException.php',
            "<?php\n\nnamespace App\\Sessions\\Domain;\n\nfinal class CapacityExceededException extends \\RuntimeException {}\n",
        );
        file_put_contents(
            $projectDir.'/src/Sessions/Application/DashboardQueryInterface.php',
            "<?php\n\nnamespace App\\Sessions\\Application;\n\ninterface DashboardQueryInterface {}\n",
        );

        $report = (new DddArchitectureValidator())->validate($projectDir);

        $taxonomyViolations = array_values(array_filter(
            $report->violations(),
            static fn (string $v): bool => str_contains($v, 'taxonomy-migrated'),
        ));
        self::assertSame([], $taxonomyViolations);
    }

    public function testMigratedContextQueryInterfaceOutsideQueryFolderIsReported(): void
    {
        $projectDir = $this->createProjectFixture();
        file_put_contents(
            $projectDir.'/src/Legal/Application/ConsentLogQueryInterface.php',
            "<?php\n\nnamespace App\\Legal\\Application;\n\ninterface ConsentLogQueryInterface {}\n",
        );

        $report = (new DddArchitectureValidator())->validate($projectDir);

        self::assertFalse($report->isSuccessful());
        self::assertContains(
            'Query interfaces must live in Application/Query/ (taxonomy-migrated context): src/Legal/Application/ConsentLogQueryInterface.php',
            $report->violations(),
        );
    }

    public function testCreateNativeQueryInPresentationIsReported(): void
    {
        $projectDir = $this->createProjectFixture();
        file_put_contents(
            $projectDir.'/src/Events/Presentation/AdminEventController.php',
            "<?php\n\nnamespace App\\Events\\Presentation;\n\nfinal class AdminEventController {\n    public function __invoke(): void { \$this->em->createNativeQuery('SELECT 1', \$rsm); }\n}\n",
        );

        $report = (new DddArchitectureValidator())->validate($projectDir);

        self::assertFalse($report->isSuccessful());
        self::assertContains(
            'Presentation layer must not execute queries directly (createNativeQuery): src/Events/Presentation/AdminEventController.php',
            $report->violations(),
        );
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
            'Membership',
            'WeeklyRuns',
            'SessionConfig',
            'Community',
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
