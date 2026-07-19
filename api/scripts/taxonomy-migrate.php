<?php

declare(strict_types=1);

/**
 * Layer/kind taxonomy migrator (story 33.20).
 *
 * Moves class files into kind sub-folders and rewrites every FQCN reference across
 * src/, tests/ and config/ - in ONE pass, transactionally checked up front.
 *
 * Why PHP and not PowerShell: the three bugs the 33.10/33.11 .ps1 tooling hit are all
 * PowerShell pathologies - prefix-unsafe `.Replace()`, case-INsensitive `-match`, and
 * .NET APIs not inheriting the PS working directory. Here the regex and the IO are
 * explicit and case-sensitive by construction, and there is no shell quoting layer to
 * mangle backslashes (see the repo's Windows-shell lesson: sed/`\` is a silent no-op).
 *
 * Word-boundary safety: an FQCN replace must not corrupt a longer FQCN that merely
 * starts with it (`...\Session` must not eat `...\SessionSlot`). Every pattern carries a
 * trailing (?![A-Za-z0-9_]) guard.
 *
 * It does NOT invent `use` statements for references that were same-namespace before the
 * move and become cross-namespace after it. That is deliberate: phpstan (level max) is a
 * complete and exact oracle for those - run it right after and fix what it names.
 *
 * Usage:
 *   php scripts/taxonomy-migrate.php <plan.json> [--dry-run]
 *
 * plan.json: { "moves": { "src/Ctx/Layer/Foo.php": "src/Ctx/Layer/Kind/Foo.php", ... } }
 */
$argv0 = $argv[0] ?? 'taxonomy-migrate.php';
$planPath = $argv[1] ?? null;
$dryRun = in_array('--dry-run', $argv, true);

if (null === $planPath || !is_file($planPath)) {
    fwrite(STDERR, "usage: php {$argv0} <plan.json> [--dry-run]\n");
    exit(1);
}

$apiRoot = dirname(__DIR__);
chdir($apiRoot);

$raw = file_get_contents($planPath);
if (false === $raw) {
    fwrite(STDERR, "cannot read plan: {$planPath}\n");
    exit(1);
}
$plan = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
if (!is_array($plan) || !isset($plan['moves']) || !is_array($plan['moves'])) {
    fwrite(STDERR, "plan must be {\"moves\": {src: dst, ...}}\n");
    exit(1);
}
/** @var array<string,string> $moves */
$moves = $plan['moves'];

// ---------------------------------------------------------------- preflight

$fqcnOf = static function (string $path): string {
    // src/Sessions/Domain/Session.php -> App\Sessions\Domain\Session
    $p = preg_replace('#^src/#', '', $path);
    $p = preg_replace('#\.php$#', '', (string) $p);

    return 'App\\'.str_replace('/', '\\', (string) $p);
};

$errors = [];
/** @var array<string,string> $fqcnMap old => new */
$fqcnMap = [];

foreach ($moves as $src => $dst) {
    if (!is_string($src) || !is_string($dst)) {
        $errors[] = 'non-string move entry';
        continue;
    }
    if (!is_file($src)) {
        $errors[] = "source missing: {$src}";
        continue;
    }
    if (is_file($dst)) {
        $errors[] = "target already exists: {$dst}";
        continue;
    }
    if (basename($src) !== basename($dst)) {
        $errors[] = "basename must not change: {$src} -> {$dst}";
        continue;
    }
    $old = $fqcnOf($src);
    $new = $fqcnOf($dst);
    if ($old === $new) {
        $errors[] = "no-op move: {$src}";
        continue;
    }
    $fqcnMap[$old] = $new;
}

if ([] !== $errors) {
    fwrite(STDERR, "PREFLIGHT FAILED:\n  - ".implode("\n  - ", $errors)."\n");
    exit(1);
}

printf("plan: %d moves\n", count($moves));

// ---------------------------------------------------------------- scan roots

$roots = ['src', 'tests', 'config'];
$scan = static function () use ($roots): array {
    $files = [];
    foreach ($roots as $root) {
        if (!is_dir($root)) {
            continue;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!$f instanceof SplFileInfo || !$f->isFile()) {
                continue;
            }
            if (!in_array($f->getExtension(), ['php', 'yaml', 'yml'], true)) {
                continue;
            }
            $rel = str_replace('\\', '/', $f->getPathname());
            // Flex-regenerated, gitignored - never touch it.
            if (str_ends_with($rel, 'config/reference.php')) {
                continue;
            }
            $files[] = $rel;
        }
    }
    sort($files);

    return $files;
};

$files = $scan();
printf("scanning %d files across %s\n", count($files), implode(', ', $roots));

// Warn on escaped-FQCN forms ("App\\Sessions\\...") which live in PHP string literals
// (validator test fixtures). The single-backslash patterns below cannot see them, so they
// must be handled by hand - surface them rather than letting them slip.
$escapedHits = [];
foreach ($files as $file) {
    $c = file_get_contents($file);
    if (false === $c) {
        continue;
    }
    foreach (array_keys($fqcnMap) as $old) {
        if (str_contains($c, str_replace('\\', '\\\\', $old))) {
            $escapedHits[$file][] = $old;
        }
    }
}

// ---------------------------------------------------------------- move + rewrite

$run = static function (array $cmd): void {
    $out = [];
    $code = 0;
    exec(implode(' ', array_map('escapeshellarg', $cmd)).' 2>&1', $out, $code);
    if (0 !== $code) {
        fwrite(STDERR, 'command failed: '.implode(' ', $cmd)."\n".implode("\n", $out)."\n");
        exit(1);
    }
};

if (!$dryRun) {
    foreach ($moves as $src => $dst) {
        $dir = dirname($dst);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            fwrite(STDERR, "cannot mkdir {$dir}\n");
            exit(1);
        }
        $run(['git', 'mv', $src, $dst]);
    }
    printf("moved %d files\n", count($moves));

    // Namespace declaration of each moved file.
    foreach ($moves as $src => $dst) {
        $oldNs = substr($fqcnOf($src), 0, (int) strrpos($fqcnOf($src), '\\'));
        $newNs = substr($fqcnOf($dst), 0, (int) strrpos($fqcnOf($dst), '\\'));
        $c = file_get_contents($dst);
        if (false === $c) {
            continue;
        }
        $patched = preg_replace(
            '/^namespace\s+'.preg_quote($oldNs, '/').'\s*;/m',
            'namespace '.$newNs.';',
            $c,
            1,
            $n,
        );
        if (1 !== $n || !is_string($patched)) {
            fwrite(STDERR, "namespace rewrite failed in {$dst} (expected 'namespace {$oldNs};')\n");
            exit(1);
        }
        file_put_contents($dst, $patched);
    }
    echo "rewrote namespace declarations\n";
}

// Re-scan AFTER the moves: the pre-move list holds stale paths, and the moved files
// themselves must be rewritten too (they reference other moved classes).
if (!$dryRun) {
    $files = $scan();
}

// Global FQCN rewrite. Longest-first is not required (the lookahead is exact) but it keeps
// the diff deterministic.
$olds = array_keys($fqcnMap);
usort($olds, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

$touched = 0;
$replacements = 0;
foreach ($files as $file) {
    $c = file_get_contents($file);
    if (false === $c) {
        continue;
    }
    $orig = $c;
    foreach ($olds as $old) {
        $pattern = '/'.preg_quote($old, '/').'(?![A-Za-z0-9_])/';
        $c = preg_replace($pattern, str_replace('\\', '\\\\', $fqcnMap[$old]), $c, -1, $n);
        if (!is_string($c)) {
            fwrite(STDERR, "regex failure on {$file}\n");
            exit(1);
        }
        $replacements += $n;
    }
    if ($c !== $orig) {
        ++$touched;
        if (!$dryRun) {
            file_put_contents($file, $c);
        }
    }
}

printf("%s: %d FQCN references rewritten across %d files\n", $dryRun ? 'DRY-RUN' : 'rewrote', $replacements, $touched);

if ([] !== $escapedHits) {
    echo "\n!! escaped-FQCN forms found (PHP string literals) - NOT rewritten, fix by hand:\n";
    foreach ($escapedHits as $file => $hits) {
        printf("   %s\n      %s\n", $file, implode("\n      ", array_unique($hits)));
    }
}

echo "\nNEXT: run `vendor/bin/phpstan analyse src tests` - it enumerates every reference that was\n";
echo "same-namespace before the move and now needs a `use`. Fix those, then php-cs-fixer.\n";
