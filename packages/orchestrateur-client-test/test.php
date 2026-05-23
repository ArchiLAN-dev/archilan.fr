<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Archilan\OrchestratorClient\Exception\ConflictException;
use Archilan\OrchestratorClient\Exception\OrchestratorException;
use Archilan\OrchestratorClient\Exception\ServiceUnavailableException;
use Archilan\OrchestratorClient\Exception\SessionNotFoundException;
use Archilan\OrchestratorClient\Exception\TransportException;
use Archilan\OrchestratorClient\OrchestratorClient;
use Archilan\OrchestratorClient\Sessions\Request\ConfigureRequest;
use Archilan\OrchestratorClient\Sessions\Request\ConfigureSlot;
use Archilan\OrchestratorClient\Sessions\Request\PreflightRequest;
use Archilan\OrchestratorClient\Sessions\Request\PreflightSlot;
use Archilan\OrchestratorClient\Sessions\Yaml\PlayerYaml;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

// ─── Config ─────────────────────────────────────────────────────────────────
$baseUrl = 'http://localhost:8001';
$apiKey = 'dev_orchestrateur_key_change_me';

$rawHttp = HttpClient::create();

$client = new OrchestratorClient(
    baseUrl: $baseUrl,
    apiKey: $apiKey,
    httpClient: $rawHttp,
);

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

function dump(mixed $value): void
{
    echo '  ' . json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
}

function probe(
    HttpClientInterface $http,
    string              $method,
    string              $url,
    string              $apiKey,
    mixed               $body = null,
): void
{
    $options = ['headers' => ['Authorization' => 'Bearer ' . $apiKey]];
    if (null !== $body) {
        $options['json'] = $body;
    }
    try {
        $r = $http->request($method, $url, $options);
        $status = $r->getStatusCode();
        $content = $r->getContent(false);
        echo "  → {$method} {$url}\n";
        echo "    HTTP {$status}  body: " . trim($content) . "\n";
    } catch (\Throwable $e) {
        echo "  → {$method} {$url}\n";
        echo "    NETWORK ERROR: " . $e->getMessage() . "\n";
    }
}

// ─── 0. Raw HTTP probes ───────────────────────────────────────────────────────
section('Raw HTTP probes (bypass PHP client)');
probe($rawHttp, 'GET', "$baseUrl/health", $apiKey);
probe($rawHttp, 'GET', "$baseUrl/sessions/probe-does-not-exist", $apiKey);
probe($rawHttp, 'POST', "$baseUrl/sessions/probe-test/generate", $apiKey, ['adminPassword' => 'x']);
probe($rawHttp, 'POST', "$baseUrl/sessions/probe-test/preflight", $apiKey, ['slots' => []]);
probe($rawHttp, 'POST', "$baseUrl/sessions/probe-test/configure", $apiKey, ['slots' => []]);

$dir = 'C:/ProgramData/Archipelago/lib/worlds/';
$directory = scandir($dir);
foreach ($directory as $file) {
    if (!str_ends_with($file, '.apworld') || !in_array($file, ['soe.apworld', 'pokemon_emerald.apworld', 'stardew_valley.apworld'])) {
        continue;
    }
    $content = file_get_contents($dir . $file);

    try {
        $client->apworlds()->upload($content, $file);
        ok("Uploaded: $file");
    } catch (\Throwable $exception) {
        err("Exeception:" . $file . ':' . $exception->getMessage());
    }
}


// ─── 1. Sessions — get inexistante → SessionNotFoundException ────────────────
section('Sessions — get() on unknown session');
try {
    $client->sessions()->get('session-does-not-exist');
    err('Expected SessionNotFoundException but nothing was thrown');
} catch (SessionNotFoundException $e) {
    ok('SessionNotFoundException caught: ' . $e->getMessage());
} catch (TransportException $e) {
    err('Orchestrateur unreachable — is it running? ' . $e->getMessage());
    exit(1);
}

// ─── 2. Sessions — generate → ConflictException si déjà en cours ────────────
section('Sessions — generate() then second generate() → ConflictException');
$sessionId = 'test-php-client-' . time();
try {
    $client->sessions()->generate($sessionId, 'adminpassword');
    ok("generate({$sessionId}) → 202 accepted");
} catch (ServiceUnavailableException $e) {
    ok('ServiceUnavailableException (storage not configured, expected in dev): ' . $e->getMessage());
} catch (OrchestratorException $e) {
    err('Unexpected exception: ' . get_class($e) . ' — ' . $e->getMessage());
}

try {
    $client->sessions()->generate($sessionId, 'adminpassword');
    err('Expected ConflictException but nothing was thrown');
} catch (ConflictException $e) {
    ok('ConflictException caught, errorCode: ' . $e->errorCode);
} catch (ServiceUnavailableException $e) {
    ok('ServiceUnavailableException on second call too (storage not configured): ' . $e->getMessage());
} catch (OrchestratorException $e) {
    err('Unexpected exception: ' . get_class($e) . ' — ' . $e->getMessage());
}

// ─── 3. Sessions — preflight ─────────────────────────────────────────────────
section('Sessions — preflight()');
try {
    $result = $client->sessions()->preflight(
        $sessionId,
        new PreflightRequest([
            new PreflightSlot(
                slotId: 'slot-1',
                playerName: 'Jean',
                archipelagoGameName: 'A Link to the Past',
            ),
            new PreflightSlot(
                slotId: 'slot-2',
                playerName: 'Bob',
                archipelagoGameName: '',   // intentionally empty → validation error
            ),
        ]),
    );
    ok('preflight() returned, valid=' . ($result->valid ? 'true' : 'false'));
    foreach ($result->slots as $slot) {
        $icon = [] === $slot->errors ? '✔' : '✘';
        echo "    [{$icon}] {$slot->slotId} → proposedName={$slot->proposedName}";
        if ([] !== $slot->errors) {
            echo ' errors=[' . implode(', ', $slot->errors) . ']';
        }
        echo "\n";
    }
} catch (OrchestratorException $e) {
    err('preflight failed: ' . get_class($e) . ' — ' . $e->getMessage());
}

// ─── 4a. ConfigureSlot — invalid hash format caught client-side ──────────────
section('ConfigureSlot — invalid hash → InvalidArgumentException');
try {
    new ConfigureSlot(
        apworldHash: 'not-a-sha256',
        playerYaml: new PlayerYaml(name: 'Jean', game: 'Test'),
    );
    err('Expected InvalidArgumentException but nothing was thrown');
} catch (\InvalidArgumentException $e) {
    ok('InvalidArgumentException caught: ' . $e->getMessage());
}

// ─── 4b. Sessions — configure (storage validation errors) ────────────────────
section('Sessions — configure() with unknown apworld hash → validation errors');
try {
    $result = $client->sessions()->configure(
        $sessionId,
        new ConfigureRequest([
            new ConfigureSlot(
                apworldHash: str_repeat('a', 64),   // valid format, unknown in storage
                playerYaml: new PlayerYaml(name: 'Jean', game: 'A Link to the Past'),
            ),
            new ConfigureSlot(
                apworldHash: str_repeat('b', 64),   // valid format, unknown in storage
                playerYaml: new PlayerYaml(name: 'Bob', game: 'Clique'),
            ),
        ]),
    );
    ok('configure() returned, valid=' . ($result->valid ? 'true' : 'false'));
    foreach ($result->slots as $i => $slot) {
        $icon = [] === $slot->errors ? '✔' : '✘';
        echo "    [{$icon}] slot {$i} → playerName='{$slot->playerName}'";
        if ([] !== $slot->errors) {
            echo ' errors=[' . implode(', ', $slot->errors) . ']';
        }
        echo "\n";
    }
    if (!$result->valid) {
        ok('Validation errors returned as expected');
    } else {
        err('Expected validation errors but configure() returned valid=true');
    }
} catch (OrchestratorException $e) {
    err('configure failed: ' . get_class($e) . ' — ' . $e->getMessage());
}

// ─── 5. End-to-end: generate → poll → launch → poll → check ─────────────────
section('End-to-end: generate → wait → launch → wait → status check');
$e2eSessionId = 'test-e2e-' . time();

// 4a. Generate
try {
    $client->sessions()->generate($e2eSessionId, 'adminpassword');
    ok("generate({$e2eSessionId}) → 202");
} catch (OrchestratorException $e) {
    err('generate failed: ' . get_class($e) . ' — ' . $e->getMessage());
    goto cleanup;
}

// 4b. Poll until status ∈ {generated, crashed} — max 60s
echo "  Polling for generated status";
$generatedOk = false;
for ($i = 0; $i < 30; $i++) {
    sleep(2);
    echo '.';
    flush();
    try {
        $sess = $client->sessions()->get($e2eSessionId);
        if ('generated' === $sess->status) {
            echo "\n";
            ok("status=generated, outputFile={$sess->outputFile}");
            $generatedOk = true;
            break;
        }
        if ('crashed' === $sess->status) {
            echo "\n";
            err("generation crashed (status=crashed)");
            goto cleanup;
        }
    } catch (OrchestratorException $e) {
        echo "\n";
        err('get() failed: ' . $e->getMessage());
        goto cleanup;
    }
}
echo "\n";

if (!$generatedOk) {
    err('Generation did not complete within 60 seconds');
    goto cleanup;
}

// 4c. Launch
try {
    $client->sessions()->launch($e2eSessionId, 'adminpassword', null);
    ok("launch({$e2eSessionId}) → 202");
} catch (OrchestratorException $e) {
    err('launch failed: ' . get_class($e) . ' — ' . $e->getMessage());
    goto cleanup;
}

// 4d. Poll until status ∈ {running, crashed} — max 90s
echo "  Polling for running status";
$runningOk = false;
for ($i = 0; $i < 45; $i++) {
    sleep(2);
    echo '.';
    flush();
    try {
        $sess = $client->sessions()->get($e2eSessionId);
        if ('running' === $sess->status) {
            echo "\n";
            ok("status=running — bridgePort={$sess->bridgePort}, apPort={$sess->apPort}");
            $runningOk = true;
            break;
        }
        if ('crashed' === $sess->status) {
            echo "\n";
            err("session crashed after launch (status=crashed)");
            // Print bridge + AP server container logs for debugging
            $bridgeName = "archilan-bridge-{$e2eSessionId}";
            $apName = "ap-server-{$e2eSessionId}";
            foreach ([$bridgeName, $apName] as $cname) {
                echo "\n  --- Docker logs: {$cname} ---\n";
                $logs = shell_exec("docker logs --tail=40 {$cname} 2>&1");
                foreach (explode("\n", (string)$logs) as $line) {
                    if ('' !== trim($line)) {
                        echo "  | {$line}\n";
                    }
                }
            }
            goto cleanup;
        }
    } catch (OrchestratorException $e) {
        echo "\n";
        err('get() failed: ' . $e->getMessage());
        goto cleanup;
    }
}
echo "\n";

if ($runningOk) {
    ok("Session {$e2eSessionId} is RUNNING — end-to-end test PASSED");
} else {
    err('Session did not reach running state within 90 seconds');
}

// ─── Cleanup ──────────────────────────────────────────────────────────────────
cleanup:
section("Cleanup — delete sessions");
foreach ([$sessionId, $e2eSessionId] as $sid) {
    try {
        $client->sessions()->delete($sid);
        ok("delete({$sid}) → 204");
    } catch (SessionNotFoundException $e) {
        ok("delete({$sid}) → session not found (ok)");
    } catch (OrchestratorException $e) {
        err("delete({$sid}) failed: " . $e->getMessage());
    }
}

// ─── 6. Containers — list ────────────────────────────────────────────────────
section('Containers — list()');
try {
    $containers = $client->containers()->list();
    ok(count($containers) . ' container(s) running');
    foreach ($containers as $c) {
        dump(['sessionId' => $c->sessionId, 'port' => $c->port, 'status' => $c->status]);
    }
} catch (OrchestratorException $e) {
    err('list() failed: ' . get_class($e) . ' — ' . $e->getMessage());
}

// ─── Done ─────────────────────────────────────────────────────────────────────
echo "\n\033[1;32mDone.\033[0m\n";


