<?php

/**
 * End-to-end bridge test.
 *
 * Workflow :
 *   1. Configure  - 1 slot Luigi's Mansion (+ toadsanity)
 *   2. Generate   - lance la génération AP
 *   3. Poll       - attend status=generated
 *   4. Launch     - démarre AP + bridge
 *   5. Poll       - attend status=running
 *   6. WS probe   - attend wsConnected=true sur le bridge
 *   7. Bridge test - exécute bridge-client-test/test.php sur le port du bridge
 *   8. Cleanup    - supprime la session
 *
 * Usage : php e2e_bridge_test.php [sessionId]
 *   sessionId  (optionnel) - généré automatiquement si absent
 */

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Archilan\OrchestratorClient\Exception\OrchestratorException;
use Archilan\OrchestratorClient\Exception\SessionNotFoundException;
use Archilan\OrchestratorClient\OrchestratorClient;
use Archilan\OrchestratorClient\Sessions\Request\ConfigureRequest;
use Archilan\OrchestratorClient\Sessions\Request\ConfigureSlot;
use Archilan\OrchestratorClient\Sessions\Request\SlotOptions;
use Archilan\OrchestratorClient\Sessions\Yaml\Option\ToggleOption;
use Symfony\Component\HttpClient\HttpClient;

// ─── Config ──────────────────────────────────────────────────────────────────

$orchestrateurUrl   = 'http://localhost:8001';
$orchestrateurKey   = 'dev_orchestrateur_key_change_me';
$bridgeToken        = 'dev_bridge_token_change_me';
$adminPassword      = 'password';
$hashLuigiMansion   = '0fd8936279e053df96110fcb7898447a9fb8655343b8f26c22108d79a73b4e21';
$sessionId          = $argv[1] ?? 'e2e-bridge-' . date('His');

$bridgeTestScript = realpath(__DIR__ . '/../bridge-client-test/test.php');
if (false === $bridgeTestScript) {
    fwrite(STDERR, "ERROR: bridge-client-test/test.php introuvable\n");
    exit(1);
}

$client = new OrchestratorClient(
    baseUrl:    $orchestrateurUrl,
    apiKey:     $orchestrateurKey,
    httpClient: HttpClient::create(),
);

// ─── Helpers ─────────────────────────────────────────────────────────────────

function section(string $title): void
{
    echo "\n\033[1;34m=== {$title} ===\033[0m\n";
}

function ok(string $msg): void
{
    echo "  \033[32m✔\033[0m {$msg}\n";
}

function err(string $msg): void
{
    echo "  \033[31m✘\033[0m {$msg}\n";
}

function bail(string $msg): never
{
    echo "  \033[1;31m✘ FATAL: {$msg}\033[0m\n";
    exit(1);
}

function poll(string $label, callable $check, int $maxSeconds = 120, int $stepSeconds = 3): bool
{
    echo "  {$label}";
    $deadline = time() + $maxSeconds;
    while (time() < $deadline) {
        if ($check()) {
            echo "\n";
            return true;
        }
        echo '.';
        flush();
        sleep($stepSeconds);
    }
    echo "\n";
    return false;
}

// ─── 1. Configure ────────────────────────────────────────────────────────────

section("Configure session - {$sessionId}");
try {
    $cfg = $client->sessions()->configure(
        $sessionId,
        new ConfigureRequest([
            ConfigureSlot::fromOptions($hashLuigiMansion, new SlotOptions(
                playerName: 'TestBridge_LM',
                options: [new ToggleOption('toadsanity', true)],
            )),
        ]),
    );
    if (!$cfg->valid) {
        $errs = implode(', ', $cfg->slots[0]->errors ?? ['unknown']);
        bail("configure() retourne invalid : {$errs}");
    }
    ok("configure() → valid");
} catch (OrchestratorException $e) {
    bail("configure() échoué : " . get_class($e) . ' - ' . $e->getMessage());
}

// ─── 2. Generate ─────────────────────────────────────────────────────────────

section("Generate");
try {
    $client->sessions()->generate($sessionId, $adminPassword);
    ok("generate() → 202");
} catch (OrchestratorException $e) {
    bail("generate() échoué : " . get_class($e) . ' - ' . $e->getMessage());
}

// ─── 3. Poll until generated ──────────────────────────────────────────────────

section("Polling - status=generated (max 120s)");
$outputFile = null;
$generated = poll('  Génération en cours', function () use ($client, $sessionId, &$outputFile): bool {
    try {
        $sess = $client->sessions()->get($sessionId);
        if ('generated' === $sess->status) {
            $outputFile = $sess->outputFile;
            return true;
        }
        if ('crashed' === $sess->status) {
            bail("Génération crashée (status=crashed)");
        }
        return false;
    } catch (OrchestratorException) {
        return false;
    }
});

if (!$generated) {
    bail("La génération n'a pas abouti en 120s");
}
ok("status=generated  outputFile={$outputFile}");

// ─── 4. Launch ───────────────────────────────────────────────────────────────

section("Launch");
try {
    $client->sessions()->launch($sessionId, $adminPassword);
    ok("launch() → 202");
} catch (OrchestratorException $e) {
    bail("launch() échoué : " . get_class($e) . ' - ' . $e->getMessage());
}

// ─── 5. Poll until running ────────────────────────────────────────────────────

section("Polling - status=running (max 120s)");
$bridgePort = null;
$apPort     = null;
$running = poll('  Démarrage en cours', function () use ($client, $sessionId, &$bridgePort, &$apPort): bool {
    try {
        $sess = $client->sessions()->get($sessionId);
        if ('running' === $sess->status) {
            $bridgePort = $sess->bridgePort;
            $apPort     = $sess->apPort;
            return true;
        }
        if ('crashed' === $sess->status) {
            // Print container logs before bailing
            foreach (["archilan-bridge-{$sessionId}", "ap-server-{$sessionId}"] as $cname) {
                echo "\n  --- Docker logs: {$cname} ---\n";
                $logs = shell_exec("docker logs --tail=30 {$cname} 2>&1");
                foreach (explode("\n", (string)$logs) as $line) {
                    if ('' !== trim($line)) {
                        echo "  | {$line}\n";
                    }
                }
            }
            bail("Session crashée au démarrage (status=crashed)");
        }
        return false;
    } catch (OrchestratorException) {
        return false;
    }
});

if (!$running) {
    bail("La session n'a pas atteint running en 120s");
}
ok("status=running  bridgePort={$bridgePort}  apPort={$apPort}");

// ─── 6. Wait for WS connection ────────────────────────────────────────────────

section("Polling - bridge wsConnected=true (max 60s)");
$bridgeUrl = "http://localhost:{$bridgePort}";
$wsReady = poll('  Connexion WebSocket AP', function () use ($bridgeUrl, $bridgeToken): bool {
    try {
        $ctx = stream_context_create(['http' => [
            'header' => "Authorization: Bearer {$bridgeToken}\r\n",
            'timeout' => 3,
        ]]);
        $body = @file_get_contents("{$bridgeUrl}/health", false, $ctx);
        if (false === $body) {
            return false;
        }
        $data = json_decode($body, true);
        return isset($data['wsConnected']) && true === $data['wsConnected'];
    } catch (\Throwable) {
        return false;
    }
}, 60, 2);

if (!$wsReady) {
    err("Bridge WS non connecté après 60s - les tests bridge peuvent être partiels");
} else {
    ok("wsConnected=true");
}

// ─── 7. Run bridge client tests ───────────────────────────────────────────────

section("Bridge client test - {$bridgeUrl}");
echo "\n";
$exitCode = 0;
passthru(
    "php " . escapeshellarg($bridgeTestScript)
    . ' ' . escapeshellarg($bridgeUrl)
    . ' ' . escapeshellarg($bridgeToken),
    $exitCode,
);

// ─── 8. Cleanup ───────────────────────────────────────────────────────────────

section("Cleanup - suppression de la session {$sessionId}");
try {
    $client->sessions()->delete($sessionId);
    ok("delete() → 204");
} catch (SessionNotFoundException) {
    ok("Session déjà supprimée");
} catch (OrchestratorException $e) {
    err("delete() échoué : " . $e->getMessage());
}

// ─── Done ─────────────────────────────────────────────────────────────────────

echo "\n";
if (0 === $exitCode) {
    echo "\033[1;32mE2E OK - tous les tests bridge sont passés.\033[0m\n";
} else {
    echo "\033[1;31mE2E PARTIEL - bridge test sorti avec code {$exitCode}.\033[0m\n";
}
