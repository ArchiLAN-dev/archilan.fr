<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Archilan\BridgeClient\BridgeClient;
use Archilan\BridgeClient\Enum\HintStatus;
use Archilan\BridgeClient\Exception\BridgeException;
use Archilan\BridgeClient\Exception\BridgeServiceUnavailableException;
use Archilan\BridgeClient\Exception\NotFoundException;
use Symfony\Component\HttpClient\HttpClient;

// ─── Config ──────────────────────────────────────────────────────────────────
$baseUrl    = 'http://localhost:25004';
$adminToken = 'dev_bridge_token_change_me';

$client = new BridgeClient(
    baseUrl:    $baseUrl,
    adminToken: $adminToken,
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

function dump(mixed $value): void
{
    echo '  ' . json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
}

// ─── Room — health ────────────────────────────────────────────────────────────
section('Room — health()');
try {
    $health = $client->room()->health();
    ok("status={$health->status}  wsConnected=" . ($health->wsConnected ? 'true' : 'false') . "  sessionId={$health->sessionId}");
} catch (BridgeException $e) {
    err('health() failed: ' . $e->getMessage());
    exit(1);
}

// ─── Room — info ──────────────────────────────────────────────────────────────
section('Room — info()');
try {
    $room = $client->room()->info();
    ok("sessionId={$room->sessionId}  slots={$room->slotCount}  hintCost={$room->hintCostPercent}%");
    ok("deathLink=" . ($room->deathLinkActive ? 'yes' : 'no') . "  race=" . ($room->raceMode ? 'yes' : 'no') . "  wsConnected=" . ($room->wsConnected ? 'yes' : 'no'));
} catch (BridgeException $e) {
    err('info() failed: ' . $e->getMessage());
}

// ─── Room — feed ──────────────────────────────────────────────────────────────
section('Room — feed(limit: 10)');
try {
    $events = $client->room()->feed(10);
    ok(count($events) . ' event(s) in feed');
    foreach (array_slice($events, 0, 3) as $event) {
        echo "    [{$event->type}] {$event->message}\n";
    }
} catch (BridgeException $e) {
    err('feed() failed: ' . $e->getMessage());
}

// ─── Room — data-package ──────────────────────────────────────────────────────
section('Room — dataPackageGames()');
try {
    $games = $client->room()->dataPackageGames();
    ok(count($games) . ' game(s) in data-package');
    foreach (array_slice($games, 0, 5) as $game) {
        echo "    - {$game}\n";
    }
    if (count($games) > 5) {
        echo '    ... ' . (count($games) - 5) . " more\n";
    }
} catch (BridgeException $e) {
    err('dataPackageGames() failed: ' . $e->getMessage());
}

// ─── Slots — list ────────────────────────────────────────────────────────────
section('Slots — list()');
$slotIndex = null;
try {
    $slots = $client->slots()->list();
    ok(count($slots) . ' slot(s)');
    foreach ($slots as $slot) {
        $connected = $slot->connected ? '🟢' : '⚫';
        echo "    {$connected} [{$slot->slot}] {$slot->name} ({$slot->game}) [{$slot->type}] — {$slot->checksDone}/{$slot->checksTotal} checks\n";
        // Prefer a real player slot for per-slot tests (not spectator/group)
        if ($slot->type === 'player') {
            $slotIndex = $slot->slot;
        } elseif ($slotIndex === null) {
            $slotIndex = $slot->slot;
        }
    }
} catch (BridgeException $e) {
    err('list() failed: ' . $e->getMessage());
}

if (null === $slotIndex) {
    echo "\n\033[33mNo slots found — skipping per-slot tests.\033[0m\n";
    goto admin_section;
}

// ─── Slots — get detail ───────────────────────────────────────────────────────
section("Slots — get({$slotIndex})");
try {
    $detail = $client->slots()->get($slotIndex);
    ok("name={$detail->name}  game={$detail->game}  status={$detail->status}");
    ok("checks={$detail->checksDone}/{$detail->checksTotal}  items={$detail->itemsReceived}  budget={$detail->budget}");
    if (null !== $detail->goalReachedAt) {
        ok("goalReachedAt={$detail->goalReachedAt}");
    }
    if (null !== $detail->reachableNow) {
        ok("reachableNow={$detail->reachableNow}");
    }
} catch (BridgeException $e) {
    err('get() failed: ' . $e->getMessage());
}

// ─── Slots — checks ───────────────────────────────────────────────────────────
section("Slots — checks({$slotIndex})");
try {
    $checks = $client->slots()->checks($slotIndex);
    ok("total={$checks->total}  checked={$checks->checkedCount}");
    $withItems = array_filter($checks->locations, fn ($l) => null !== $l->item);
    ok(count($withItems) . ' location(s) have item details');
    foreach (array_slice(array_values($withItems), 0, 2) as $loc) {
        echo "    [{$loc->locationId}] {$loc->locationName} → {$loc->item?->name}\n";
    }
} catch (BridgeException $e) {
    err('checks() failed: ' . $e->getMessage());
}

// ─── Slots — items ────────────────────────────────────────────────────────────
section("Slots — items({$slotIndex})");
try {
    $items = $client->slots()->items($slotIndex);
    ok("totalOwned={$items->totalOwned}  received={$items->receivedCount}");
    foreach (array_slice($items->items, 0, 3) as $item) {
        $received = $item->received ? '✔' : '○';
        echo "    [{$received}] {$item->name}";
        if (null !== $item->foundAt) {
            echo " — found at {$item->foundAt->locationName} by {$item->foundAt->findingPlayerName}";
        }
        echo "\n";
    }
} catch (BridgeException $e) {
    err('items() failed: ' . $e->getMessage());
}

// ─── Slots — hints ────────────────────────────────────────────────────────────
section("Slots — hints({$slotIndex})");
$firstUnfoundHintLocation = null;
try {
    $hints = $client->slots()->hints($slotIndex);
    ok("hintsUsed={$hints->hintsUsed}  pointsAvailable={$hints->hintPointsAvailable}  cost={$hints->hintCost}");
    ok(count($hints->hints) . ' hint(s)');
    foreach (array_slice($hints->hints, 0, 3) as $hint) {
        $icon = $hint->found ? '✔' : '○';
        echo "    [{$icon}] {$hint->itemName} @ {$hint->locationName} — status={$hint->status->label()}\n";
        if (!$hint->found && null === $firstUnfoundHintLocation) {
            $firstUnfoundHintLocation = $hint->locationId;
        }
    }
} catch (BridgeException $e) {
    err('hints() failed: ' . $e->getMessage());
}

// ─── Slots — updateHint (HintStatus enum) ────────────────────────────────────
if (null !== $firstUnfoundHintLocation) {
    section("Slots — updateHint({$slotIndex}, locationId={$firstUnfoundHintLocation}, Priority)");
    try {
        $ok = $client->slots()->updateHint($slotIndex, $firstUnfoundHintLocation, HintStatus::Priority);
        ok("locationId={$ok->locationId}  free=" . ($ok->free ? 'true' : 'false'));
        // Reset to Unspecified
        $client->slots()->updateHint($slotIndex, $firstUnfoundHintLocation, HintStatus::Unspecified);
        ok('Reset back to Unspecified');
    } catch (BridgeException $e) {
        err('updateHint() failed: ' . $e->getMessage());
    }
}

// ─── Slots — reachable ────────────────────────────────────────────────────────
section("Slots — reachable({$slotIndex})");
try {
    $reachable = $client->slots()->reachable($slotIndex);
    ok("player={$reachable->player}  cached=" . ($reachable->cached ? 'yes' : 'no'));
    ok('reachableUnchecked=' . count($reachable->reachableUnchecked)
        . '  reachableChecked=' . count($reachable->reachableChecked)
        . '  unreachableUnchecked=' . count($reachable->unreachableUnchecked));
    foreach (array_slice($reachable->reachableUnchecked, 0, 2) as $loc) {
        echo "    → {$loc->locationName} [{$loc->locationId}]\n";
    }
} catch (BridgeException $e) {
    err('reachable() failed: ' . $e->getMessage());
}

// ─── Slots — itemLocations ────────────────────────────────────────────────────
section("Slots — itemLocations({$slotIndex})");
try {
    $locs = $client->slots()->itemLocations($slotIndex);
    ok(count($locs->locations) . ' item-location(s)');
    foreach (array_slice($locs->locations, 0, 3) as $loc) {
        echo "    {$loc->itemName} → {$loc->locationName} [{$loc->checkStatus}]\n";
    }
} catch (BridgeException $e) {
    err('itemLocations() failed: ' . $e->getMessage());
}

// ─── Slots — NotFoundException ────────────────────────────────────────────────
section('Slots — get() on unknown slot → NotFoundException');
try {
    $client->slots()->get(9999);
    err('Expected NotFoundException but nothing was thrown');
} catch (NotFoundException $e) {
    ok('NotFoundException caught: ' . $e->getMessage());
} catch (BridgeException $e) {
    err('Unexpected exception: ' . get_class($e) . ' — ' . $e->getMessage());
}

// ─── Admin ────────────────────────────────────────────────────────────────────
admin_section:
// Trigger reachability for the player slot so /spheres has cached data
if (null !== $slotIndex) {
    try {
        $client->slots()->reachable($slotIndex);
    } catch (BridgeException) {
        // reachable may fail (500 if world not supported) — spheres still returns cached=false
    }
}

section('Admin — spheres()');
try {
    $spheres = $client->admin()->spheres();
    ok(count($spheres->spheres) . ' sphere(s)  cached=' . ($spheres->cached ? 'yes' : 'no'));
    if (!empty($spheres->spheres)) {
        $first = $spheres->spheres[0];
        echo "    Sphere 0: " . count($first->locations) . " location(s)\n";
    }
} catch (BridgeServiceUnavailableException $e) {
    ok('ServiceUnavailable (ws not connected — expected if bridge is disconnected): ' . $e->getMessage());
} catch (BridgeException $e) {
    err('spheres() failed: ' . $e->getMessage());
}

if (null !== $slotIndex) {
    section("Admin — missingItems({$slotIndex})");
    try {
        $missing = $client->admin()->missingItems($slotIndex);
        ok(count($missing->missing) . ' missing item(s) for slot ' . $missing->slot);
        foreach (array_slice($missing->missing, 0, 3) as $p) {
            echo "    {$p->itemName} @ {$p->locationName} → {$p->receivingPlayerName}\n";
        }
    } catch (BridgeException $e) {
        err('missingItems() failed: ' . $e->getMessage());
    }

    section("Admin — slotSpoiler({$slotIndex})");
    try {
        $spoiler = $client->admin()->slotSpoiler($slotIndex);
        ok(count($spoiler->placements) . ' placement(s)');
        foreach (array_slice($spoiler->placements, 0, 3) as $p) {
            echo "    {$p->itemName} @ {$p->locationName} → {$p->receivingPlayerName}\n";
        }
    } catch (BridgeException $e) {
        err('slotSpoiler() failed: ' . $e->getMessage());
    }
}

section('Admin — sendCommand(!help)');
try {
    $client->admin()->sendCommand('!help');
    ok('sendCommand(!help) → ok');
} catch (BridgeServiceUnavailableException $e) {
    ok('ServiceUnavailable (ws not connected): ' . $e->getMessage());
} catch (BridgeException $e) {
    err('sendCommand() failed: ' . $e->getMessage());
}

// ─── Done ─────────────────────────────────────────────────────────────────────
echo "\n\033[1;32mDone.\033[0m\n";
