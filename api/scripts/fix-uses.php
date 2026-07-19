<?php

declare(strict_types=1);

/**
 * Missing-`use` resolver for the taxonomy migration (story 33.20).
 *
 * After a move, a reference that used to be same-namespace (needing no import) becomes
 * cross-namespace. phpstan names every one of them; this reads phpstan's JSON report,
 * resolves each unresolved short class name against the real on-disk class index, and
 * inserts the correct `use` into the file's header import block.
 *
 * It never guesses: a name is only imported if exactly one class with that short name
 * exists in src/. Ambiguities are reported and left alone.
 *
 * Usage:
 *   vendor/bin/phpstan analyse src tests --no-progress --error-format=json > ps.json
 *   php scripts/fix-uses.php ps.json
 */
$reportPath = $argv[1] ?? null;
if (null === $reportPath || !is_file($reportPath)) {
    fwrite(STDERR, "usage: php scripts/fix-uses.php <phpstan-report.json>\n");
    exit(1);
}

chdir(dirname(__DIR__));

// ---- index every class in src/ by short name
$index = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator('src', FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if (!$f instanceof SplFileInfo || !$f->isFile() || 'php' !== $f->getExtension()) {
        continue;
    }
    $rel = str_replace('\\', '/', $f->getPathname());
    $rel = substr($rel, (int) strpos($rel, 'src/'));
    $fqcn = 'App\\'.str_replace('/', '\\', substr($rel, 4, -4));
    $short = substr($fqcn, (int) strrpos($fqcn, '\\') + 1);
    $index[$short][] = $fqcn;
}

$report = json_decode((string) file_get_contents($reportPath), true, 512, JSON_THROW_ON_ERROR);
if (!is_array($report) || !isset($report['files']) || !is_array($report['files'])) {
    fwrite(STDERR, "unreadable phpstan report\n");
    exit(1);
}

$cwd = str_replace('\\', '/', getcwd());
$needed = [];   // file => [fqcn, ...]
$ambiguous = [];
$unknown = [];

foreach ($report['files'] as $file => $data) {
    $file = str_replace('\\', '/', (string) $file);
    $file = str_starts_with($file, $cwd.'/') ? substr($file, strlen($cwd) + 1) : $file;

    foreach ($data['messages'] as $m) {
        // "... class App\Sessions\Application\Service\RunnerGatewayInterface not found."
        if (1 !== preg_match('/(App\\\\[A-Za-z0-9_\\\\]+)/', (string) $m['message'], $mm)) {
            continue;
        }
        $bogus = $mm[1];
        $short = substr($bogus, (int) strrpos($bogus, '\\') + 1);

        $candidates = $index[$short] ?? [];
        // The bogus FQCN is by construction NOT a real class - drop any self-match.
        $candidates = array_values(array_filter($candidates, static fn (string $c): bool => $c !== $bogus));

        if ([] === $candidates) {
            $unknown[$short] = true;
            continue;
        }
        if (count($candidates) > 1) {
            $ambiguous[$short] = $candidates;
            continue;
        }
        $needed[$file][$candidates[0]] = true;
    }
}

$patched = 0;
$added = 0;
foreach ($needed as $file => $fqcns) {
    if (!is_file($file)) {
        continue;
    }
    $contents = (string) file_get_contents($file);
    $lines = explode("\n", $contents);

    // Locate the header `use` block (top-level imports only - never a heredoc's inner `use`:
    // stop at the first class/interface/trait/enum/attribute declaration).
    $lastUse = null;
    $nsLine = null;
    foreach ($lines as $i => $line) {
        if (1 === preg_match('/^namespace\s+/', $line)) {
            $nsLine = $i;
        }
        if (1 === preg_match('/^use\s+[A-Za-z\\\\]/', $line)) {
            $lastUse = $i;
        }
        if (1 === preg_match('/^(final |abstract |readonly |#\[)/', $line)) {
            break;
        }
    }
    $insertAt = $lastUse ?? $nsLine;
    if (null === $insertAt) {
        continue;
    }

    $new = [];
    foreach (array_keys($fqcns) as $fqcn) {
        if (1 === preg_match('/^use\s+'.preg_quote($fqcn, '/').'\s*;/m', $contents)) {
            continue; // already imported
        }
        $new[] = 'use '.$fqcn.';';
    }
    if ([] === $new) {
        continue;
    }

    array_splice($lines, $insertAt + 1, 0, $new);
    file_put_contents($file, implode("\n", $lines));
    ++$patched;
    $added += count($new);
    printf("%s\n", $file);
    foreach ($new as $u) {
        printf("    + %s\n", $u);
    }
}

printf("\nadded %d use statements across %d files\n", $added, $patched);

if ([] !== $ambiguous) {
    echo "\nAMBIGUOUS (left alone - resolve by hand):\n";
    foreach ($ambiguous as $short => $cands) {
        printf("  %s -> %s\n", $short, implode(' | ', $cands));
    }
}
if ([] !== $unknown) {
    echo "\nUNRESOLVED short names (not a class in src/ - probably an unrelated error):\n  ".implode("\n  ", array_keys($unknown))."\n";
}

echo "\nNEXT: vendor/bin/php-cs-fixer fix (import order), then re-run phpstan.\n";
