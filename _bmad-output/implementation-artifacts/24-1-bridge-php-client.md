# Story 24.1: Bridge PHP Client Library

## Story

**As a** PHP application (Symfony API ou tout autre consommateur PHP),
**I want** un package Composer autonome et typé qui enveloppe l'API REST du Bridge,
**So that** je manipule l'état d'une session Archipelago — slots, hints, reachability, feed, commandes admin — exclusivement via des objets PHP, sans toucher au HTTP ni aux tableaux bruts.

## Status

done

## Context

Le Bridge expose une API REST en deux niveaux :
- **Publics** (sans auth) : room, slots, hints (lecture), reachable, checks, items, feed, data-package
- **Admin** (Bearer token) : commandes, pause, resume, deathlink, spoiler, spheres, items manquants

Structure cible calquée sur `archilan/orchestrateur-client` (déjà dans `packages/orchestrateur-client/`).

---

## Acceptance Criteria

### AC1 — Scaffold du package

`packages/bridge-client/` contient :
- `composer.json` : `name: "archilan/bridge-client"`, `type: "library"`, PHP `^8.2`, require `symfony/http-client-contracts: ^3.0`. PSR-4 : `Archilan\\BridgeClient\\` → `src/`.
- `.gitignore` ignorant `vendor/` et `.phpunit.result.cache`.
- `api/composer.json` ajoute un dépôt `path` pointant vers `../packages/bridge-client` et require `archilan/bridge-client: *`.

---

### AC2 — Point d'entrée `BridgeClient`

```php
$client = new BridgeClient(
    baseUrl:    'http://bridge:8080',
    adminToken: 'secret',
    httpClient: $symfonyHttpClient,
);

$client->room()   // RoomClient
$client->slots()  // SlotsClient
$client->admin()  // AdminClient
```

Aucun état global. Tout passé à la construction. `BridgeClient` instancie un `HttpTransport` partagé injecté dans chaque sous-client.

---

### AC3 — `HttpTransport` (interne, pas d'API publique)

Centralise toute la plomberie HTTP :
- Injecte `Authorization: Bearer {adminToken}` sur **toutes** les requêtes.
- Méthodes : `getJson(string $path): array`, `getRaw(string $path): string`, `postJson(string $path, array $body = []): array`, `postVoid(string $path, array $body = []): void`, `patchJson(string $path, array $body = []): array`, `deleteVoid(string $path): void`.
- Un seul `mapError()` privé : `404` → `NotFoundException`, `503` → `BridgeServiceUnavailableException`, reste → `BridgeException`.

---

### AC4 — `HintStatus` BackedEnum

```php
enum HintStatus: int
{
    case Unspecified = 0;
    case NoPriority  = 10;
    case Avoid       = 20;
    case Priority    = 30;
    case Found       = 40;

    public function label(): string { /* "unspecified", "no_priority", ... */ }
    public function isFound(): bool { return $this === self::Found; }
}
```

Utilisé partout où un statut de hint apparaît — jamais de `int` brut exposé dans l'API publique du client.

---

### AC5 — DTOs (tous `final readonly`)

Chaque DTO est `final readonly class` avec un unique `static fromArray(array $data): self`. Aucun setter public. Les champs nullables sont typés `?Type` et non `mixed`.

**Room & session :**
```php
class HealthResponse          { status: string, wsConnected: bool, sessionId: string }
class RoomInfo                { sessionId, slotCount, hintCostPercent, locationCheckPoints,
                                forfeitMode, releaseMode, collectMode, deathLinkActive,
                                raceMode: bool, wsConnected: bool }
class FeedEvent               { type: string, message: string, timestamp: ?string }
```

**Slots :**
```php
class SlotSummary             { slot: int, name: string, game: string, type: string,
                                status: string, connected: bool,
                                checksDone: int, checksTotal: int }
class SlotDetail extends SlotSummary
                              { itemsReceived: int, goalReachedAt: ?string,
                                reachableNow: ?int, budget: int }
```

**Checks :**
```php
class CheckItem               { id: int, name: string, flags: int,
                                receivingSlot: int, receivingPlayerName: string }
class CheckLocation           { locationId: int, locationName: string,
                                checked: bool, item: ?CheckItem }
class SlotChecksResponse      { slot: int, total: int, checkedCount: int,
                                locations: CheckLocation[] }
```

**Items :**
```php
class ItemFoundAt             { findingSlot: int, findingPlayerName: string,
                                locationId: int, locationName: string, checked: bool }
class SlotItem                { id: int, name: string, flags: int,
                                received: bool, foundAt: ?ItemFoundAt }
class SlotItemsResponse       { slot: int, totalOwned: int, receivedCount: int,
                                items: SlotItem[] }
```

**Hints :**
```php
class Hint {
    receivingSlot: int, receivingPlayerName: string,
    findingSlot: int, findingPlayerName: string,
    locationId: int, locationName: string,
    itemId: int, itemName: string,
    itemFlags: int, entrance: string,
    status: HintStatus,   // ← enum, pas int brut
    found: bool           // dérivé de status === Found
}
class HintsResponse           { slot: int, hints: Hint[], hintsUsed: int,
                                hintPointsAvailable: int, hintCost: int }
class HintOkResponse          { slot: int, locationId: int, free: bool }
```

**Reachability :**
```php
class ReachableLocation       { locationId: int, locationName: string }
class ReachableResponse       { slot: int, player: string,
                                reachableUnchecked: ReachableLocation[],
                                reachableChecked: ReachableLocation[],
                                unreachableUnchecked: ReachableLocation[],
                                cached: bool }
```

**Item-locations :**
```php
class ItemLocation            { itemId: int, itemName: string,
                                locationId: int, locationName: string,
                                findingSlot: int, findingPlayerName: ?string,
                                checkStatus: string }
class ItemLocationsResponse   { slot: int, locations: ItemLocation[] }
```

**Admin :**
```php
class LocationPlacement       { locationId: int, locationName: string,
                                itemId: int, itemName: string,
                                receivingSlot: int, receivingPlayerName: string }
class SpoilerResponse         { placements: LocationPlacement[] }
class Sphere                  { index: int, locations: LocationPlacement[] }
class SpheresResponse         { cached: bool, spheres: Sphere[] }
class MissingItemsResponse    { slot: int, missing: LocationPlacement[] }
```

---

### AC6 — `RoomClient`

```php
public function health(): HealthResponse
public function info(): RoomInfo                          // GET /room
public function feed(int $limit = 50): FeedEvent[]        // GET /feed
public function dataPackageGames(): string[]              // GET /data-package
public function dataPackage(string $game): array          // GET /data-package/{game}
```

---

### AC7 — `SlotsClient`

```php
public function list(): SlotSummary[]
public function get(int $slot): SlotDetail
public function checks(int $slot): SlotChecksResponse
public function items(int $slot): SlotItemsResponse
public function hints(int $slot): HintsResponse
public function requestHint(int $slot, int $locationId, bool $free = false): HintOkResponse
public function updateHint(int $slot, int $locationId, HintStatus $status): HintOkResponse
public function reachable(int $slot): ReachableResponse
public function itemLocations(int $slot): ItemLocationsResponse
```

`updateHint` prend un `HintStatus` — l'appelant ne manipule jamais d'entier brut.

---

### AC8 — `AdminClient`

```php
public function sendCommand(string $command): void
public function pause(): void
public function resume(?string $saveKey = null): void
public function deathlink(string $source, ?string $cause = null): void
public function missingItems(int $slot): MissingItemsResponse
public function slotSpoiler(int $slot): SpoilerResponse
public function spoiler(): SpoilerResponse
public function spheres(): SpheresResponse
```

---

### AC9 — Hiérarchie d'exceptions

```
BridgeException (extends RuntimeException)
├── NotFoundException
└── BridgeServiceUnavailableException
```

Aucune méthode supplémentaire — le message du constructeur suffit.

---

### AC10 — Qualité

```bash
cd packages/bridge-client && vendor/bin/phpstan analyse src --level=9   # 0 erreurs
cd api && vendor/bin/phpstan analyse src tests                           # 0 erreurs (inchangé)
```

---

## Tasks

- [ ] Scaffold `packages/bridge-client/` (composer.json, src/, .gitignore)
- [ ] Implémenter `HintStatus` BackedEnum
- [ ] Implémenter `HttpTransport` (auth + mapError)
- [ ] Implémenter tous les DTOs avec `fromArray()`
- [ ] Implémenter `RoomClient`
- [ ] Implémenter `SlotsClient` (slots, checks, items, hints, reachable, item-locations)
- [ ] Implémenter `AdminClient`
- [ ] Implémenter `BridgeClient` (point d'entrée)
- [ ] Wirer `api/composer.json` (path repo + require)
- [ ] PHPStan level 9 clean
