<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use App\Shared\Application\Support\DddArchitectureValidator;
use PHPUnit\Framework\TestCase;

/**
 * The point of story 33.23, expressed as a test.
 *
 * Every content rule of the validator is a raw `str_contains`/`preg_match` over
 * `file_get_contents`, so it cannot tell code from a comment or a string literal. The
 * consequence is that a rule matches its own documentation - a bill four stories each
 * paid (33.13, 33.15, 33.16, 33.17) and that the source now openly admits.
 *
 * This fixture is a file whose CODE is perfectly clean, but whose doc-comment and string
 * literals contain every pattern the rules scan for. A tokenizer-based validator reports
 * nothing. A raw-text one reports a pile of phantoms.
 *
 * This test FAILS before the tokenizer refactor. That is deliberate: a test that only
 * passes after the change proves nothing about the change.
 */
final class ValidatorIgnoresCommentsAndStringsTest extends TestCase
{
    private ?string $projectDir = null;

    protected function tearDown(): void
    {
        if (null !== $this->projectDir && is_dir($this->projectDir)) {
            $this->removeDirectory($this->projectDir);
        }
    }

    public function testPatternsInsideCommentsAndStringsAreNotViolations(): void
    {
        $projectDir = $this->createMinimalProject();

        // An Application service whose CODE is clean. Its doc-comment quotes the forbidden
        // forms (as real documentation would), and its code carries them as string literals
        // (as an error message or a scan pattern would).
        $decoy = <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App\Events\Application\Service;

            /**
             * Documentation that legitimately quotes the rules it must respect:
             *
             *  - never write `new \DateTimeImmutable()` here - inject the clock;
             *  - never add a `public function setTitle(string $t): void` to an aggregate;
             *  - never import App\Events\Infrastructure\DoctrineEventRepository from here;
             *  - never gate on isGranted('ROLE_MEMBER').
             */
            final class HouseRules
            {
                public function describe(): string
                {
                    $forbiddenClock = 'new \DateTimeImmutable()';
                    $forbiddenSetter = 'public function setTitle';
                    $forbiddenImport = 'App\Events\Infrastructure\DoctrineEventRepository';
                    $forbiddenGate = "isGranted('ROLE_MEMBER')";

                    return $forbiddenClock.$forbiddenSetter.$forbiddenImport.$forbiddenGate;
                }
            }

            PHP;

        $this->write($projectDir.'/src/Events/Application/Service/HouseRules.php', $decoy);

        // Same idea in the Domain layer: the aggregate-setter and finality rules must not be
        // fooled by a doc-comment either.
        $domainDecoy = <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App\Events\Domain\Entity;

            /**
             * This aggregate deliberately has NO setters. Historically it exposed
             * `public function setTitle(string $title): void` - replaced by rename().
             */
            final class Poster
            {
                private string $title = '';

                public function rename(string $title): void
                {
                    $this->title = $title;
                }
            }

            PHP;

        $this->write($projectDir.'/src/Events/Domain/Entity/Poster.php', $domainDecoy);

        $report = new DddArchitectureValidator()->validate($projectDir);

        $phantom = array_values(array_filter(
            $report->violations(),
            static fn (string $v): bool => str_contains($v, 'HouseRules.php') || str_contains($v, 'Poster.php'),
        ));

        self::assertSame(
            [],
            $phantom,
            "The validator matched its own documentation. Every content rule scans raw file text, so a\n"
            ."pattern quoted in a doc-comment or held in a string literal is indistinguishable from real\n"
            ."code. This is the raw-lexical-scan debt story 33.23 exists to remove:\n  - "
            .implode("\n  - ", $phantom),
        );
    }

    /**
     * The smallest tree the validator accepts: every context's four layer dirs, plus the
     * config files it reads.
     */
    private function createMinimalProject(): string
    {
        $projectDir = sys_get_temp_dir().'/archilan-tokenizer-'.bin2hex(random_bytes(6));
        $this->projectDir = $projectDir;

        $contexts = [
            'Shared', 'Identity', 'Events', 'Registrations', 'GameSelection', 'Content',
            'Payments', 'Realtime', 'Communications', 'Legal', 'Sessions', 'PersonalRuns',
            'CatalogSync', 'Streaming', 'Membership', 'WeeklyRuns', 'SessionConfig', 'Community',
        ];

        foreach ($contexts as $context) {
            foreach (['Domain', 'Application', 'Infrastructure', 'Presentation'] as $layer) {
                $this->makeDir("{$projectDir}/src/{$context}/{$layer}");
            }
        }

        $this->makeDir($projectDir.'/config/packages');

        $services = "services:\n    App\\:\n        resource: '../src/'\n        exclude:\n";
        foreach ($contexts as $context) {
            $services .= "            - '../src/{$context}/Domain/'\n";
        }
        $this->write($projectDir.'/config/services.yaml', $services);

        // Only Events has an entity in this fixture, so only Events needs a mapping.
        $doctrine = "doctrine:\n    orm:\n        mappings:\n            Events:\n"
            ."                type: attribute\n"
            ."                dir: '%kernel.project_dir%/src/Events/Domain/Entity'\n"
            ."                prefix: 'App\\Events\\Domain\\Entity'\n";
        $this->write($projectDir.'/config/packages/doctrine.yaml', $doctrine);

        return $projectDir;
    }

    private function write(string $path, string $contents): void
    {
        $this->makeDir(dirname($path));
        file_put_contents($path, $contents);
    }

    private function makeDir(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0o777, true) && !is_dir($path)) {
            self::fail("cannot create {$path}");
        }
    }

    private function removeDirectory(string $path): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            if (!$item instanceof \SplFileInfo) {
                continue;
            }
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
