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

        $report = new DddArchitectureValidator()->validate($projectDir);

        self::assertTrue($report->isSuccessful(), implode("\n", $report->violations()));
        self::assertSame([], $report->violations());
    }

    public function testPhpFileOutsideDddLayerIsReported(): void
    {
        $projectDir = $this->createProjectFixture();
        file_put_contents($projectDir.'/src/Events/EventHelper.php', "<?php\n");

        $report = new DddArchitectureValidator()->validate($projectDir);

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

        $report = new DddArchitectureValidator()->validate($projectDir);

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

        $report = new DddArchitectureValidator()->validate($projectDir);

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

        $report = new DddArchitectureValidator()->validate($projectDir);

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

        $report = new DddArchitectureValidator()->validate($projectDir);

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

        $report = new DddArchitectureValidator()->validate($projectDir);

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

        $report = new DddArchitectureValidator()->validate($projectDir);

        $cqrsViolationsForApplicationFile = array_values(array_filter(
            $report->violations(),
            static fn (string $v): bool => str_contains($v, 'EventQuery.php') && str_contains($v, 'Presentation layer'),
        ));
        self::assertCount(0, $cqrsViolationsForApplicationFile, 'Application layer DB imports must not trigger CQRS violations');
    }

    public function testCleanPresentationControllerIsNotReported(): void
    {
        $projectDir = $this->createProjectFixture();
        $this->createDirectory($projectDir.'/src/Events/Presentation/Controller');
        file_put_contents(
            $projectDir.'/src/Events/Presentation/Controller/AdminEventController.php',
            "<?php\n\nnamespace App\\Events\\Presentation\\Controller;\n\nfinal class AdminEventController {\n    public function __construct(private object \$catalog) {}\n}\n",
        );

        $report = new DddArchitectureValidator()->validate($projectDir);

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

        $report = new DddArchitectureValidator()->validate($projectDir);

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

        $report = new DddArchitectureValidator()->validate($projectDir);

        self::assertFalse($report->isSuccessful());
        self::assertContains(
            'Cross-context dependency on another context\'s Presentation layer ("App\\Payments\\Presentation\\"): src/Events/Infrastructure/WeirdBridge.php',
            $report->violations(),
        );
    }

    public function testCrossContextDomainAndApplicationImportsAreAllowed(): void
    {
        $projectDir = $this->createProjectFixture();
        $this->createDirectory($projectDir.'/src/Registrations/Application/Command');
        file_put_contents(
            $projectDir.'/src/Registrations/Application/Command/ReserveSeat.php',
            "<?php\n\nnamespace App\\Registrations\\Application\\Command;\n\nuse App\\Events\\Domain\\Event;\nuse App\\Payments\\Application\\PaymentLookup;\n\nfinal class ReserveSeat {}\n",
        );

        $report = new DddArchitectureValidator()->validate($projectDir);

        $violationsForFile = array_values(array_filter(
            $report->violations(),
            static fn (string $v): bool => str_contains($v, 'ReserveSeat.php'),
        ));
        self::assertSame([], $violationsForFile);
    }

    public function testSharedInfrastructureImportIsAllowedInApplication(): void
    {
        $projectDir = $this->createProjectFixture();
        $this->createDirectory($projectDir.'/src/Events/Application/Support');
        file_put_contents(
            $projectDir.'/src/Events/Application/Support/CoverImageReader.php',
            "<?php\n\nnamespace App\\Events\\Application\\Support;\n\nuse App\\Shared\\Infrastructure\\MinioStorageInterface;\n\nfinal class CoverImageReader {}\n",
        );

        $report = new DddArchitectureValidator()->validate($projectDir);

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

        $report = new DddArchitectureValidator()->validate($projectDir);

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

        $report = new DddArchitectureValidator()->validate($projectDir);

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

        $report = new DddArchitectureValidator()->validate($projectDir);

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

        $report = new DddArchitectureValidator()->validate($projectDir);

        self::assertFalse($report->isSuccessful());
        self::assertContains(
            'Application layer must not depend on the Infrastructure layer ("App\\Events\\Infrastructure\\"): src/Events/Application/GalleryService.php',
            $report->violations(),
        );
    }

    /**
     * The Application->Infrastructure allowlist is gone (story 33.20): its last two entries
     * were these very Sessions runner-callback handlers, and extracting
     * RunnerCallbackClientInterface removed the need for them. The rule now holds
     * unconditionally - the formerly-exempt path is reported like any other.
     */
    public function testFormerlyAllowlistedApplicationInfrastructureImportIsNowReported(): void
    {
        $projectDir = $this->createProjectFixture();
        $this->createDirectory($projectDir.'/src/Sessions/Application/Handler');
        file_put_contents(
            $projectDir.'/src/Sessions/Application/Handler/ArchiveRunJobHandler.php',
            "<?php\n\nnamespace App\\Sessions\\Application\\Handler;\n\nuse App\\Sessions\\Infrastructure\\Http\\RunnerCallbackClient;\n\nfinal class ArchiveRunJobHandler {}\n",
        );

        $report = new DddArchitectureValidator()->validate($projectDir);

        self::assertFalse($report->isSuccessful());
        self::assertContains(
            'Application layer must not depend on the Infrastructure layer ("App\\Sessions\\Infrastructure\\"): src/Sessions/Application/Handler/ArchiveRunJobHandler.php',
            $report->violations(),
        );
    }

    public function testApplicationNewOnInfrastructureIsReported(): void
    {
        $projectDir = $this->createProjectFixture();
        file_put_contents(
            $projectDir.'/src/Events/Application/InlineFactory.php',
            "<?php\n\nnamespace App\\Events\\Application;\n\nfinal class InlineFactory {\n    public function make(): object { return new \\App\\Events\\Infrastructure\\GalleryHttpClient(); }\n}\n",
        );

        $report = new DddArchitectureValidator()->validate($projectDir);

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

        $report = new DddArchitectureValidator()->validate($projectDir);

        self::assertFalse($report->isSuccessful());
        self::assertContains(
            'Application layer must not call date() - inject a clock or pass the value as a parameter: src/Events/Application/ClockUser.php',
            $report->violations(),
        );
    }

    public function testApplicationClockLookalikesAreNotReported(): void
    {
        $projectDir = $this->createProjectFixture();
        $this->createDirectory($projectDir.'/src/Events/Application/Support');
        file_put_contents(
            $projectDir.'/src/Events/Application/Support/NotAClock.php',
            "<?php\n\nnamespace App\\Events\\Application\\Support;\n\nfinal class NotAClock {\n"
            ."    public function run(object \$repo, string \$raw, \\DateTimeImmutable \$now): void {\n"
            ."        \$repo->update(\$raw);\n"
            ."        \$parsed = new \\DateTimeImmutable(\$raw);\n"
            ."        \$ts = strtotime(\$raw);\n"
            ."        \$repo->time(\$parsed, \$ts, \$now);\n"
            ."    }\n"
            ."}\n",
        );

        $report = new DddArchitectureValidator()->validate($projectDir);

        $violationsForFile = array_values(array_filter(
            $report->violations(),
            static fn (string $v): bool => str_contains($v, 'NotAClock.php'),
        ));
        self::assertSame([], $violationsForFile);
    }

    public function testApplicationZeroArgDateTimeConstructIsReported(): void
    {
        $projectDir = $this->createProjectFixture();
        file_put_contents(
            $projectDir.'/src/Events/Application/StampNow.php',
            "<?php\n\nnamespace App\\Events\\Application;\n\nfinal class StampNow {\n"
            ."    public function immutable(): \\DateTimeImmutable { return new \\DateTimeImmutable(); }\n"
            ."    public function mutableNow(): \\DateTime { return new \\DateTime('now'); }\n"
            ."}\n",
        );

        $report = new DddArchitectureValidator()->validate($projectDir);

        self::assertFalse($report->isSuccessful());
        self::assertContains(
            'Application layer must not construct DateTimeImmutable() to read the wall clock - inject Psr\Clock\ClockInterface and call $this->clock->now(): src/Events/Application/StampNow.php',
            $report->violations(),
        );
        self::assertContains(
            'Application layer must not construct DateTime() to read the wall clock - inject Psr\Clock\ClockInterface and call $this->clock->now(): src/Events/Application/StampNow.php',
            $report->violations(),
        );
    }

    public function testApplicationArgumentedDateTimeConstructIsNotReported(): void
    {
        $projectDir = $this->createProjectFixture();
        $this->createDirectory($projectDir.'/src/Events/Application/Support');
        file_put_contents(
            $projectDir.'/src/Events/Application/Support/ParseInstant.php',
            "<?php\n\nnamespace App\\Events\\Application\\Support;\n\nfinal class ParseInstant {\n"
            ."    public function fixed(): \\DateTimeImmutable { return new \\DateTimeImmutable('2026-01-01T00:00:00+00:00'); }\n"
            ."    public function fromRaw(string \$raw): \\DateTimeImmutable { return new \\DateTimeImmutable(\$raw); }\n"
            ."}\n",
        );

        $report = new DddArchitectureValidator()->validate($projectDir);

        $violationsForFile = array_values(array_filter(
            $report->violations(),
            static fn (string $v): bool => str_contains($v, 'ParseInstant.php'),
        ));
        self::assertSame([], $violationsForFile);
    }

    public function testFrozenContextZeroArgDateTimeConstructIsNotReported(): void
    {
        $projectDir = $this->createProjectFixture();
        file_put_contents(
            $projectDir.'/src/Sessions/Application/SessionStamp.php',
            "<?php\n\nnamespace App\\Sessions\\Application;\n\nfinal class SessionStamp {\n"
            ."    public function now(): \\DateTimeImmutable { return new \\DateTimeImmutable(); }\n"
            ."}\n",
        );

        $report = new DddArchitectureValidator()->validate($projectDir);

        $clockConstructViolations = array_values(array_filter(
            $report->violations(),
            static fn (string $v): bool => str_contains($v, 'SessionStamp.php') && str_contains($v, 'read the wall clock'),
        ));
        self::assertSame([], $clockConstructViolations);
    }

    public function testDomainPublicSetterIsReported(): void
    {
        $projectDir = $this->createProjectFixture();
        $this->createDirectory($projectDir.'/src/Events/Domain/Entity');
        file_put_contents(
            $projectDir.'/src/Events/Domain/Entity/Widget.php',
            "<?php\n\nnamespace App\\Events\\Domain\\Entity;\n\nfinal class Widget {\n"
            ."    private string \$name = '';\n"
            ."    public function setName(string \$name): void { \$this->name = \$name; }\n"
            ."}\n",
        );

        $report = new DddArchitectureValidator()->validate($projectDir);

        self::assertFalse($report->isSuccessful());
        self::assertContains(
            'Domain layer must not expose public setters ("public function setName") - replace with a named business method (api/CLAUDE.md AC-D5): src/Events/Domain/Entity/Widget.php',
            $report->violations(),
        );
    }

    public function testDomainSetterModifierVariantsAndMultipleSettersAreReported(): void
    {
        $projectDir = $this->createProjectFixture();
        $this->createDirectory($projectDir.'/src/Events/Domain/Entity');
        file_put_contents(
            $projectDir.'/src/Events/Domain/Entity/Gadget.php',
            "<?php\n\nnamespace App\\Events\\Domain\\Entity;\n\nclass Gadget {\n"
            ."    private string \$label = '';\n"
            ."    private int \$rank = 0;\n"
            ."    public final function setLabel(string \$label): void { \$this->label = \$label; }\n"
            ."    public function setRank(int \$rank): void { \$this->rank = \$rank; }\n"
            ."}\n",
        );

        $report = new DddArchitectureValidator()->validate($projectDir);

        self::assertFalse($report->isSuccessful());
        self::assertContains(
            'Domain layer must not expose public setters ("public final function setLabel") - replace with a named business method (api/CLAUDE.md AC-D5): src/Events/Domain/Entity/Gadget.php',
            $report->violations(),
        );
        self::assertContains(
            'Domain layer must not expose public setters ("public function setRank") - replace with a named business method (api/CLAUDE.md AC-D5): src/Events/Domain/Entity/Gadget.php',
            $report->violations(),
        );
    }

    public function testDomainSetterLookalikesAreNotReported(): void
    {
        $projectDir = $this->createProjectFixture();
        $this->createDirectory($projectDir.'/src/Events/Domain/Entity');
        file_put_contents(
            $projectDir.'/src/Events/Domain/Entity/Invoice.php',
            "<?php\n\nnamespace App\\Events\\Domain\\Entity;\n\nfinal class Invoice {\n"
            ."    private bool \$settled = false;\n"
            ."    private string \$offset = '';\n"
            ."    public function settle(): void { \$this->settled = true; }\n"
            ."    public function reset(): void { \$this->settled = false; }\n"
            ."    private function setOffset(string \$offset): void { \$this->offset = \$offset; }\n"
            ."    public function shift(string \$offset): void { \$this->setOffset(\$offset); }\n"
            ."}\n",
        );

        $report = new DddArchitectureValidator()->validate($projectDir);

        $violationsForFile = array_values(array_filter(
            $report->violations(),
            static fn (string $v): bool => str_contains($v, 'Invoice.php'),
        ));
        self::assertSame([], $violationsForFile);
    }

    public function testFrozenContextDomainSetterIsNotReported(): void
    {
        $projectDir = $this->createProjectFixture();
        file_put_contents(
            $projectDir.'/src/Sessions/Domain/SessionThing.php',
            "<?php\n\nnamespace App\\Sessions\\Domain;\n\nfinal class SessionThing {\n"
            ."    private ?string \$logs = null;\n"
            ."    public function setLastLogs(?string \$logs): void { \$this->logs = \$logs; }\n"
            ."}\n",
        );

        $report = new DddArchitectureValidator()->validate($projectDir);

        $setterViolations = array_values(array_filter(
            $report->violations(),
            static fn (string $v): bool => str_contains($v, 'SessionThing.php') && str_contains($v, 'public setters'),
        ));
        self::assertSame([], $setterViolations);
    }

    public function testDomainSymfonyImportIsReported(): void
    {
        $projectDir = $this->createProjectFixture();
        $this->createDirectory($projectDir.'/src/Events/Domain/Entity');
        file_put_contents(
            $projectDir.'/src/Events/Domain/Entity/ClockHolder.php',
            "<?php\n\nnamespace App\\Events\\Domain\\Entity;\n\nuse Symfony\\Component\\Clock\\ClockInterface;\n\nfinal class ClockHolder {}\n",
        );

        $report = new DddArchitectureValidator()->validate($projectDir);

        self::assertFalse($report->isSuccessful());
        self::assertContains(
            'Domain layer has forbidden dependency "Symfony\\Component\\": src/Events/Domain/Entity/ClockHolder.php',
            $report->violations(),
        );
    }

    public function testAllowlistedDomainSymfonyImportIsNotReported(): void
    {
        $projectDir = $this->createProjectFixture();
        $this->createDirectory($projectDir.'/src/Identity/Domain/Entity');
        file_put_contents(
            $projectDir.'/src/Identity/Domain/Entity/User.php',
            "<?php\n\nnamespace App\\Identity\\Domain\\Entity;\n\n"
            ."use Symfony\\Component\\Security\\Core\\User\\PasswordAuthenticatedUserInterface;\n"
            ."use Symfony\\Component\\Security\\Core\\User\\UserInterface;\n\n"
            ."final class User {}\n",
        );

        $report = new DddArchitectureValidator()->validate($projectDir);

        $symfonyViolations = array_values(array_filter(
            $report->violations(),
            static fn (string $v): bool => str_contains($v, 'Identity/Domain/Entity/User.php') && str_contains($v, 'forbidden dependency'),
        ));
        self::assertSame([], $symfonyViolations);
    }

    public function testMembershipGatingIsReported(): void
    {
        $projectDir = $this->createProjectFixture();
        file_put_contents(
            $projectDir.'/src/Events/Presentation/GateCheck.php',
            "<?php\n\nnamespace App\\Events\\Presentation;\n\nfinal class GateCheck {\n"
            ."    public function check(object \$auth): bool { return \$auth->isGranted('ROLE_MEMBER'); }\n"
            ."}\n",
        );

        $report = new DddArchitectureValidator()->validate($projectDir);

        self::assertFalse($report->isSuccessful());
        $gatingViolations = array_values(array_filter(
            $report->violations(),
            static fn (string $v): bool => str_contains($v, 'GateCheck.php') && str_contains($v, 'AC-M1'),
        ));
        self::assertCount(1, $gatingViolations);
    }

    public function testMembershipRoleDisplayReadIsNotReported(): void
    {
        $projectDir = $this->createProjectFixture();
        file_put_contents(
            $projectDir.'/src/Events/Presentation/RoleBadge.php',
            "<?php\n\nnamespace App\\Events\\Presentation;\n\nfinal class RoleBadge {\n"
            ."    public function badge(array \$roles): string { return in_array('ROLE_MEMBER', \$roles, true) ? 'member' : 'user'; }\n"
            ."}\n",
        );

        $report = new DddArchitectureValidator()->validate($projectDir);

        $gatingViolations = array_values(array_filter(
            $report->violations(),
            static fn (string $v): bool => str_contains($v, 'RoleBadge.php') && str_contains($v, 'AC-M1'),
        ));
        self::assertSame([], $gatingViolations);
    }

    public function testNonFinalDomainEntityIsReported(): void
    {
        $projectDir = $this->createProjectFixture();
        $this->createDirectory($projectDir.'/src/Events/Domain/Entity');
        file_put_contents(
            $projectDir.'/src/Events/Domain/Entity/Loose.php',
            "<?php\n\nnamespace App\\Events\\Domain\\Entity;\n\nclass Loose {}\n",
        );

        $report = new DddArchitectureValidator()->validate($projectDir);

        self::assertFalse($report->isSuccessful());
        self::assertContains(
            'Domain Entity classes must be declared "final class" (api/CLAUDE.md AC-D4): src/Events/Domain/Entity/Loose.php',
            $report->violations(),
        );
    }

    public function testValueObjectWithoutReadonlyIsReported(): void
    {
        $projectDir = $this->createProjectFixture();
        $this->createDirectory($projectDir.'/src/Events/Domain/ValueObject');
        file_put_contents(
            $projectDir.'/src/Events/Domain/ValueObject/Money.php',
            "<?php\n\nnamespace App\\Events\\Domain\\ValueObject;\n\nfinal class Money {}\n",
        );
        file_put_contents(
            $projectDir.'/src/Events/Domain/ValueObject/Amount.php',
            "<?php\n\nnamespace App\\Events\\Domain\\ValueObject;\n\nfinal readonly class Amount {}\n",
        );

        $report = new DddArchitectureValidator()->validate($projectDir);

        self::assertFalse($report->isSuccessful());
        self::assertContains(
            'Domain ValueObject classes must be declared "final readonly class" (api/CLAUDE.md AC-D4): src/Events/Domain/ValueObject/Money.php',
            $report->violations(),
        );
        $amountViolations = array_values(array_filter(
            $report->violations(),
            static fn (string $v): bool => str_contains($v, 'Amount.php'),
        ));
        self::assertSame([], $amountViolations);
    }

    public function testFrozenContextNonFinalDomainClassIsNotReported(): void
    {
        $projectDir = $this->createProjectFixture();
        $this->createDirectory($projectDir.'/src/Sessions/Domain/Entity');
        file_put_contents(
            $projectDir.'/src/Sessions/Domain/Entity/Legacy.php',
            "<?php\n\nnamespace App\\Sessions\\Domain\\Entity;\n\nclass Legacy {}\n",
        );

        $report = new DddArchitectureValidator()->validate($projectDir);

        $finalityViolations = array_values(array_filter(
            $report->violations(),
            static fn (string $v): bool => str_contains($v, 'Legacy.php') && str_contains($v, 'AC-D4'),
        ));
        self::assertSame([], $finalityViolations);
    }

    public function testNonFinalApplicationClassIsReported(): void
    {
        $projectDir = $this->createProjectFixture();
        $this->createDirectory($projectDir.'/src/Events/Application/Support');
        file_put_contents(
            $projectDir.'/src/Events/Application/Support/OpenHelper.php',
            "<?php\n\nnamespace App\\Events\\Application\\Support;\n\nclass OpenHelper {}\n",
        );

        $report = new DddArchitectureValidator()->validate($projectDir);

        self::assertFalse($report->isSuccessful());
        self::assertContains(
            'Application classes must be final (api/CLAUDE.md AC-A1): src/Events/Application/Support/OpenHelper.php',
            $report->violations(),
        );
    }

    public function testAllowlistedApplicationBaseClassIsNotReported(): void
    {
        $projectDir = $this->createProjectFixture();
        $this->createDirectory($projectDir.'/src/Communications/Application/Email');
        file_put_contents(
            $projectDir.'/src/Communications/Application/Email/ArchilanEmail.php',
            "<?php\n\nnamespace App\\Communications\\Application\\Email;\n\nabstract class ArchilanEmail {}\n",
        );

        $report = new DddArchitectureValidator()->validate($projectDir);

        $finalityViolations = array_values(array_filter(
            $report->violations(),
            static fn (string $v): bool => str_contains($v, 'ArchilanEmail.php') && str_contains($v, 'AC-A1'),
        ));
        self::assertSame([], $finalityViolations);
    }

    public function testApplicationEntityReturnIsReported(): void
    {
        $projectDir = $this->createProjectFixture();
        $this->createDirectory($projectDir.'/src/Events/Application/Query');
        file_put_contents(
            $projectDir.'/src/Events/Application/Query/EventFetcher.php',
            "<?php\n\nnamespace App\\Events\\Application\\Query;\n\nuse App\\Events\\Domain\\Entity\\Event;\n\nfinal class EventFetcher {\n"
            ."    public function fetch(string \$id): ?Event { return null; }\n"
            ."}\n",
        );

        $report = new DddArchitectureValidator()->validate($projectDir);

        self::assertFalse($report->isSuccessful());
        self::assertContains(
            'Application methods must not return Domain entities ("?Event") - return a DTO, record or array instead (api/CLAUDE.md AC-A3): src/Events/Application/Query/EventFetcher.php',
            $report->violations(),
        );
    }

    public function testApplicationPrivateEntityReturnIsNotReported(): void
    {
        $projectDir = $this->createProjectFixture();
        $this->createDirectory($projectDir.'/src/Events/Application/Query');
        file_put_contents(
            $projectDir.'/src/Events/Application/Query/EventPresenter.php',
            "<?php\n\nnamespace App\\Events\\Application\\Query;\n\nuse App\\Events\\Domain\\Entity\\Event;\n\nfinal class EventPresenter {\n"
            ."    public function present(string \$id): array { \$event = \$this->load(\$id); return []; }\n"
            ."    private function load(string \$id): ?Event { return null; }\n"
            ."}\n",
        );

        $report = new DddArchitectureValidator()->validate($projectDir);

        $entityReturnViolations = array_values(array_filter(
            $report->violations(),
            static fn (string $v): bool => str_contains($v, 'EventPresenter.php') && str_contains($v, 'AC-A3'),
        ));
        self::assertSame([], $entityReturnViolations);
    }

    public function testApplicationTernaryEntityBranchIsNotReported(): void
    {
        $projectDir = $this->createProjectFixture();
        $this->createDirectory($projectDir.'/src/Events/Application/Query');
        file_put_contents(
            $projectDir.'/src/Events/Application/Query/EventChooser.php',
            "<?php\n\nnamespace App\\Events\\Application\\Query;\n\nuse App\\Events\\Domain\\Entity\\Event;\n\nfinal class EventChooser {\n"
            ."    public function choose(bool \$flag): array { \$e = \$flag ? \$this->pick(\$flag) : Event::class; return [\$e]; }\n"
            ."    private function pick(bool \$flag): ?Event { return null; }\n"
            ."}\n",
        );

        $report = new DddArchitectureValidator()->validate($projectDir);

        $entityReturnViolations = array_values(array_filter(
            $report->violations(),
            static fn (string $v): bool => str_contains($v, 'EventChooser.php') && str_contains($v, 'AC-A3'),
        ));
        self::assertSame([], $entityReturnViolations);
    }

    public function testApplicationPrivateFinalEntityReturnIsNotReported(): void
    {
        $projectDir = $this->createProjectFixture();
        $this->createDirectory($projectDir.'/src/Events/Application/Query');
        file_put_contents(
            $projectDir.'/src/Events/Application/Query/EventLoader.php',
            "<?php\n\nnamespace App\\Events\\Application\\Query;\n\nuse App\\Events\\Domain\\Entity\\Event;\n\nfinal class EventLoader {\n"
            ."    public function load(string \$id): array { \$e = \$this->find(\$id); return []; }\n"
            ."    private static function find(string \$id): ?Event { return null; }\n"
            ."}\n",
        );

        $report = new DddArchitectureValidator()->validate($projectDir);

        $entityReturnViolations = array_values(array_filter(
            $report->violations(),
            static fn (string $v): bool => str_contains($v, 'EventLoader.php') && str_contains($v, 'AC-A3'),
        ));
        self::assertSame([], $entityReturnViolations);
    }

    public function testApplicationAliasedUnionEntityReturnIsReported(): void
    {
        $projectDir = $this->createProjectFixture();
        $this->createDirectory($projectDir.'/src/Events/Application/Query');
        file_put_contents(
            $projectDir.'/src/Events/Application/Query/EventAliasFetcher.php',
            "<?php\n\nnamespace App\\Events\\Application\\Query;\n\nuse App\\Events\\Domain\\Entity\\Event as EventRecord;\n\nfinal class EventAliasFetcher {\n"
            ."    public function fetch(string \$id): EventRecord|false { return false; }\n"
            ."}\n",
        );

        $report = new DddArchitectureValidator()->validate($projectDir);

        self::assertFalse($report->isSuccessful());
        $entityReturnViolations = array_values(array_filter(
            $report->violations(),
            static fn (string $v): bool => str_contains($v, 'EventAliasFetcher.php') && str_contains($v, 'AC-A3'),
        ));
        self::assertCount(1, $entityReturnViolations);
    }

    public function testMembershipGatingNamedArgumentAttributeIsReported(): void
    {
        $projectDir = $this->createProjectFixture();
        file_put_contents(
            $projectDir.'/src/Events/Presentation/AttributeGate.php',
            "<?php\n\nnamespace App\\Events\\Presentation;\n\nfinal class AttributeGate {\n"
            ."    #[IsGranted(attribute: 'ROLE_MEMBER')]\n"
            ."    public function view(): void {}\n"
            ."}\n",
        );

        $report = new DddArchitectureValidator()->validate($projectDir);

        self::assertFalse($report->isSuccessful());
        $gatingViolations = array_values(array_filter(
            $report->violations(),
            static fn (string $v): bool => str_contains($v, 'AttributeGate.php') && str_contains($v, 'AC-M1'),
        ));
        self::assertCount(1, $gatingViolations);
    }

    public function testAllowlistedAuthResolverEntityReturnIsNotReported(): void
    {
        $projectDir = $this->createProjectFixture();
        $this->createDirectory($projectDir.'/src/Identity/Application/Service');
        file_put_contents(
            $projectDir.'/src/Identity/Application/Service/AuthenticateUser.php',
            "<?php\n\nnamespace App\\Identity\\Application\\Service;\n\nuse App\\Identity\\Domain\\Entity\\User;\n\nfinal class AuthenticateUser {\n"
            ."    public function authenticate(string \$email): ?User { return null; }\n"
            ."}\n",
        );

        $report = new DddArchitectureValidator()->validate($projectDir);

        $entityReturnViolations = array_values(array_filter(
            $report->violations(),
            static fn (string $v): bool => str_contains($v, 'AuthenticateUser.php') && str_contains($v, 'AC-A3'),
        ));
        self::assertSame([], $entityReturnViolations);
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

        $report = new DddArchitectureValidator()->validate($projectDir);

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

        $report = new DddArchitectureValidator()->validate($projectDir);

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

        $report = new DddArchitectureValidator()->validate($projectDir);

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

        $report = new DddArchitectureValidator()->validate($projectDir);

        self::assertFalse($report->isSuccessful());
        self::assertContains(
            'Query interfaces must live in Application/Query/ (taxonomy-migrated context): src/Legal/Application/ConsentLogQueryInterface.php',
            $report->violations(),
        );
    }

    public function testFlatFileDirectlyInLayerIsReportedForMigratedContext(): void
    {
        $projectDir = $this->createProjectFixture();
        file_put_contents(
            $projectDir.'/src/Events/Application/LooseHelper.php',
            "<?php\n\nnamespace App\\Events\\Application;\n\nfinal class LooseHelper {}\n",
        );

        $report = new DddArchitectureValidator()->validate($projectDir);

        self::assertFalse($report->isSuccessful());
        self::assertContains(
            'No file may sit directly in a layer folder; move it into a kind sub-folder: src/Events/Application/LooseHelper.php',
            $report->violations(),
        );
    }

    public function testFlatFileInSubFolderIsNotReported(): void
    {
        $projectDir = $this->createProjectFixture();
        $this->createDirectory($projectDir.'/src/Events/Application/Support');
        file_put_contents(
            $projectDir.'/src/Events/Application/Support/LooseHelper.php',
            "<?php\n\nnamespace App\\Events\\Application\\Support;\n\nfinal class LooseHelper {}\n",
        );

        $report = new DddArchitectureValidator()->validate($projectDir);

        $flatViolations = array_values(array_filter(
            $report->violations(),
            static fn (string $v): bool => str_contains($v, 'LooseHelper'),
        ));
        self::assertSame([], $flatViolations);
    }

    public function testFlatFileInFrozenContextIsNotReported(): void
    {
        $projectDir = $this->createProjectFixture();
        file_put_contents(
            $projectDir.'/src/Sessions/Application/LooseHelper.php',
            "<?php\n\nnamespace App\\Sessions\\Application;\n\nfinal class LooseHelper {}\n",
        );

        $report = new DddArchitectureValidator()->validate($projectDir);

        $flatViolations = array_values(array_filter(
            $report->violations(),
            static fn (string $v): bool => str_contains($v, 'LooseHelper'),
        ));
        self::assertSame([], $flatViolations);
    }

    public function testCreateNativeQueryInPresentationIsReported(): void
    {
        $projectDir = $this->createProjectFixture();
        file_put_contents(
            $projectDir.'/src/Events/Presentation/AdminEventController.php',
            "<?php\n\nnamespace App\\Events\\Presentation;\n\nfinal class AdminEventController {\n    public function __invoke(): void { \$this->em->createNativeQuery('SELECT 1', \$rsm); }\n}\n",
        );

        $report = new DddArchitectureValidator()->validate($projectDir);

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

        $this->createDirectory($projectDir.'/src/Events/Domain/Entity');
        file_put_contents($projectDir.'/src/Events/Domain/Entity/Event.php', <<<'PHP'
<?php

namespace App\Events\Domain\Entity;

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
                dir: '%kernel.project_dir%/src/Events/Domain/Entity'
                prefix: 'App\Events\Domain\Entity'
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
