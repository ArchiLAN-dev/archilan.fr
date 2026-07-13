<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use App\Shared\Application\Support\DddArchitectureValidator;
use PHPUnit\Framework\TestCase;

/**
 * The standards docs are a GATE, not a good intention (epic-33 retro, action A1).
 *
 * Epic 33 existed to stop the docs lying about the enforced tooling - and it still ended
 * with `api/CLAUDE.md` citing `DddArchitectureValidator::UNMIGRATED_TAXONOMY_CONTEXTS`, a
 * constant story 33.20 had deleted. The drift came back inside the very epic that was
 * fighting it, because a doc has nothing that fails when it goes stale.
 *
 * This test gives it one. It asserts only what is MECHANICALLY checkable - no prose
 * matching, so no false positives:
 *
 *  1. every `DddArchitectureValidator::X` symbol the docs cite actually exists;
 *  2. the context list `api/CLAUDE.md` calls "authoritative" really equals CONTEXTS;
 *  3. every leg of `composer gates` is documented, and the docs invent no extra leg.
 *
 * Prose claims (which kind lives in which sub-folder, what is "validator-gated") stay
 * reviewer-enforced - a test that guessed at them would be worse than none.
 */
final class StandardsDocsMatchToolingTest extends TestCase
{
    private const string API_DIR = __DIR__.'/../../..';

    public function testEveryValidatorSymbolCitedInTheDocsExists(): void
    {
        $known = $this->validatorSymbols();
        $cited = [];

        foreach ($this->docFiles() as $path => $contents) {
            if (0 === preg_match_all('/DddArchitectureValidator::([A-Za-z_][A-Za-z0-9_]*)/', $contents, $m)) {
                continue;
            }
            foreach ($m[1] as $symbol) {
                $cited[$symbol][] = $this->shortPath($path);
            }
        }

        self::assertNotSame([], $cited, 'the docs must still reference the validator - if this fires, the docs stopped citing it at all');

        $unknown = [];
        foreach ($cited as $symbol => $files) {
            if (!in_array($symbol, $known, true)) {
                $unknown[] = sprintf('%s (cited in %s)', $symbol, implode(', ', array_unique($files)));
            }
        }

        self::assertSame(
            [],
            $unknown,
            "The docs cite validator symbols that do not exist. Either restore them or fix the docs\n"
            ."(the enforced rule wins, the doc follows - story 33.2's rule):\n  - ".implode("\n  - ", $unknown),
        );
    }

    public function testDocumentedContextListMatchesTheValidator(): void
    {
        $doc = $this->read(self::API_DIR.'/CLAUDE.md');

        // The line right after the "Known contexts (authoritative list: ...)" sentence
        // enumerates them as backtick-quoted names separated by middots.
        if (1 !== preg_match('/Known contexts \(authoritative list.*?\):\s*\n\s*\n(.+)\n/', $doc, $m)) {
            self::fail('api/CLAUDE.md must keep enumerating the bounded contexts under a "Known contexts (authoritative list: ...)" heading');
        }

        preg_match_all('/`([A-Za-z]+)`/', $m[1], $names);
        $documented = $names[1];
        sort($documented);

        $actual = $this->contexts();
        sort($actual);

        self::assertSame(
            $actual,
            $documented,
            'api/CLAUDE.md calls its context list authoritative, so it must equal DddArchitectureValidator::CONTEXTS. '
            .'Adding a bounded context means updating both.',
        );
    }

    public function testEveryComposerGatesLegIsDocumented(): void
    {
        $expected = $this->gateTools();
        $documented = $this->documentedGateTools();

        self::assertSame(
            $expected,
            $documented,
            "api/CLAUDE.md's \"What it runs, in order\" block must list exactly the legs `composer gates` really runs.\n"
            .'Expected: '.implode(', ', $expected)."\nDocumented: ".implode(', ', $documented),
        );
    }

    /**
     * Every public/private constant and method name on the validator.
     *
     * @return list<string>
     */
    private function validatorSymbols(): array
    {
        $reflection = new \ReflectionClass(DddArchitectureValidator::class);

        $symbols = array_keys($reflection->getConstants());
        foreach ($reflection->getMethods() as $method) {
            $symbols[] = $method->getName();
        }

        /** @var list<string> $symbols */
        $symbols = array_values(array_unique(array_map(strval(...), $symbols)));

        return $symbols;
    }

    /**
     * @return list<string>
     */
    private function contexts(): array
    {
        $constants = new \ReflectionClass(DddArchitectureValidator::class)->getConstants();
        $contexts = $constants['CONTEXTS'] ?? null;
        self::assertIsArray($contexts);

        $out = [];
        foreach ($contexts as $context) {
            self::assertIsString($context);
            $out[] = $context;
        }

        return $out;
    }

    /**
     * The tools `composer gates` really invokes, in order.
     *
     * @return list<string>
     */
    private function gateTools(): array
    {
        $composer = json_decode($this->read(self::API_DIR.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($composer);
        $scripts = $composer['scripts'] ?? null;
        self::assertIsArray($scripts);

        $gates = $scripts['gates'] ?? null;
        self::assertIsArray($gates, 'composer.json must define a `gates` script');

        $tools = [];
        foreach ($gates as $leg) {
            self::assertIsString($leg);
            $name = ltrim($leg, '@');
            $command = $scripts[$name] ?? null;
            self::assertIsString($command, sprintf('gates references `%s`, which is not a composer script', $name));
            $tools[] = $this->toolOf($command);
        }

        return $tools;
    }

    /**
     * The tools the docs SAY `composer gates` runs.
     *
     * @return list<string>
     */
    private function documentedGateTools(): array
    {
        $doc = $this->read(self::API_DIR.'/CLAUDE.md');

        if (1 !== preg_match('/What it runs, in order:\s*\n\s*```bash\n(.*?)```/s', $doc, $m)) {
            self::fail('api/CLAUDE.md must keep a "What it runs, in order:" bash block describing composer gates');
        }

        $tools = [];
        foreach (explode("\n", $m[1]) as $line) {
            $line = trim($line);
            if ('' === $line) {
                continue;
            }
            $tools[] = $this->toolOf($line);
        }

        return $tools;
    }

    /**
     * `php bin/console app:architecture:ddd` -> app:architecture:ddd
     * `php vendor/bin/php-cs-fixer fix --dry-run` -> php-cs-fixer
     * `php bin/phpunit` -> phpunit.
     *
     * Deliberately identity-only: the doc may spell a tool's flags differently from the
     * script (`php-cs-fixer check` IS `fix --dry-run --diff`), and asserting on flags
     * would fail on a difference that is not drift.
     */
    private function toolOf(string $command): string
    {
        if (1 === preg_match('#bin/console\s+([\w:.-]+)#', $command, $m)) {
            return $m[1];
        }

        if (1 === preg_match('#(?:vendor/)?bin/([\w.-]+)#', $command, $m)) {
            return $m[1];
        }

        self::fail(sprintf('cannot identify the tool invoked by: %s', $command));
    }

    /**
     * @return array<string,string> path => contents
     */
    private function docFiles(): array
    {
        $candidates = [
            self::API_DIR.'/CLAUDE.md',
            self::API_DIR.'/../CLAUDE.md',
            self::API_DIR.'/../frontend/AGENTS.md',
            self::API_DIR.'/../packages/CLAUDE.md',
        ];

        $docs = [];
        foreach ($candidates as $path) {
            if (is_file($path)) {
                $docs[$path] = $this->read($path);
            }
        }

        self::assertNotSame([], $docs);

        return $docs;
    }

    private function read(string $path): string
    {
        $contents = file_get_contents($path);
        self::assertIsString($contents, sprintf('cannot read %s', $path));

        return $contents;
    }

    private function shortPath(string $path): string
    {
        $normalised = str_replace('\\', '/', $path);
        $pos = strrpos($normalised, '/');

        return false === $pos ? $normalised : substr($normalised, $pos + 1);
    }
}
