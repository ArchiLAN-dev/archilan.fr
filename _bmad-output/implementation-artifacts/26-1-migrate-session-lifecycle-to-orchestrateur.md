# Story 26.1 - Migrer le cycle de vie des sessions vers l'orchestrateur

**Epic:** 26 - Élimination du runner Symfony intégré  
**Branch:** `feature/epic-26-story-1-migrate-session-lifecycle-to-orchestrateur`  
**Status:** Todo

---

## Context

Le Symfony API gère actuellement les containers Docker de session directement via
`DockerSocketClient` et des Messenger handlers (`GenerateRunJobHandler`, `StartRunJobHandler`,
`StopRunJobHandler`, `RestartRunJobHandler`, `RunHealthCheckJobHandler`). Ces handlers
s'auto-appellent ensuite via `RunnerCallbackClient` → `/api/v1/internal/sessions/{id}/runner-callback`
pour faire avancer la machine à états.

L'orchestrateur (service Go) expose déjà les mêmes opérations (`generate`, `launch`, `stop`,
`restart`) et notifie Symfony via des webhooks HMAC-signés (`session.generated`, `session.ready`,
`session.crashed`). Le client PHP `archilan/orchestrateur-client` couvre déjà toutes ces méthodes
(`SessionsClient::generate()`, `::launch()`, `::stop()`, `::restart()`, `::configure()`).

Cette story supprime la couche Docker locale et branche Symfony sur l'orchestrateur.

---

## Format du webhook (Go - `webhook.Payload`)

```json
{
  "event": "session.generated | session.ready | session.crashed",
  "sessionId": "...",
  "port": 12345,
  "error": "...",
  "timestamp": "2026-..."
}
```

- `port` : bridge port - présent seulement sur `session.ready`
- `error` : présent seulement sur `session.crashed`
- Auth : header `X-Signature-256: sha256=<hmac-sha256-hex>` (secret partagé)

---

## Acceptance Criteria

### AC1 - `configure()` + `generate()` remplacent `GenerateRunJobHandler`

`SessionOrchestrator::orchestrateValidate()` :
- Calcule les slots avec `enrichSlotsForValidation()` (déjà fait)
- Construit un `ConfigureRequest` : un `ConfigureSlot::fromYaml(apworldHash, playerYaml)` par
  slot (le hash vient de `Game::getApworldHash()`)
- Appelle `$this->client->sessions()->configure($sessionId, $configureRequest)`
- Si la réponse contient une erreur : `transition($sessionId, STATUS_DRAFT)` + retourne errors

`SessionOrchestrator::orchestrateGenerate()` :
- Génère `$adminPassword = bin2hex(random_bytes(16))`
- Appelle `$this->client->sessions()->generate($sessionId, $adminPassword)`
- L'`adminPassword` est stocké dans la session via `SessionLifecycleManager::storePendingCredentials()`
  (voir AC3) avant l'appel async

`GenerateRunJobHandler`, `GenerateRunJob` (message), et la phase `validate` sont supprimés.

### AC2 - `launch()` remplace `StartRunJobHandler`

`SessionOrchestrator::orchestrateLaunch()` :
- Récupère `$adminPassword` depuis la session (stocké en AC1)
- Génère `$serverPassword = bin2hex(random_bytes(8))` (mot de passe joueurs)
- Stocke `serverPassword` + `host = $this->runnerPublicHost` dans la session via
  `SessionLifecycleManager::storePendingCredentials()` avant l'appel async
- Appelle `$this->client->sessions()->launch($sessionId, $adminPassword, $serverPassword)`
- `StartRunJobHandler`, `StartRunJob` (message) sont supprimés

### AC3 - `SessionLifecycleManager::storePendingCredentials()` 

Nouvelle méthode qui stocke `adminPassword`, `serverPassword`, `host` dans la session en `STATUS_LAUNCHING`
**avant** l'appel async à l'orchestrateur. Permet au webhook `session.ready` de retrouver les
credentials sans qu'ils aient été envoyés dans le payload du webhook.

### AC4 - `stop()` remplace `StopRunJobHandler`

`SessionOrchestrator::orchestrateStop()` :
- Appelle directement `$this->client->sessions()->stop($sessionId)` (synchrone, sans Messenger)
- `StopRunJobHandler`, `StopRunJob` (message) sont supprimés

### AC5 - `restart()` remplace `RestartRunJobHandler`

`SessionOrchestrator::orchestrateRestart()` :
- Appelle directement `$this->client->sessions()->restart($sessionId)` (synchrone, sans Messenger)
- `RestartRunJobHandler`, `RestartRunJob` (message) sont supprimés

### AC6 - `RunHealthCheckJobHandler` supprimé

La détection de crash est déléguée au sweeper de l'orchestrateur (goroutine, 30s).
`RunHealthCheckJobHandler`, `RunHealthCheckJob` (message) sont supprimés.

### AC7 - Nouveau `OrchestratorWebhookController`

Route : `POST /api/v1/internal/orchestrateur/webhook`  
Auth : vérifie `X-Signature-256` avec HMAC-SHA256 et `ORCHESTRATEUR_WEBHOOK_SECRET`

| `event` | Action |
|---|---|
| `session.generated` | `sessionLifecycleManager->transition($id, STATUS_GENERATED)` puis `sessionOrchestrator->autoAdvancePersonalRun($id)` |
| `session.ready` | `sessionLifecycleManager->transition($id, STATUS_RUNNING, host: stored, port: $payload->port, password: stored, serverPassword: stored)` |
| `session.crashed` | `sessionLifecycleManager->transition($id, STATUS_CRASHED)` |

Signature invalide → HTTP 401. Event inconnu → HTTP 200 (ignoré silencieusement).

### AC8 - `RunnerCallbackController` allégé

Conserve uniquement les statuts `logs` et `archived` (envoyés par le bridge/AP directement).
Supprime le traitement des statuts de cycle de vie (`running`, `failed`, `crashed`, `generated`,
`ready`, `launching`) puisqu'ils arrivent désormais via webhook orchestrateur.

### AC9 - Env vars

**Ajoutée :**
- `ORCHESTRATEUR_WEBHOOK_SECRET` - secret HMAC partagé entre l'orchestrateur et Symfony

**Supprimées de `.env` et `services.yaml` :**
- `ARCHIPELAGO_SERVER_IMAGE`
- `ARCHIPELAGO_WORKSPACE_VOLUME`
- `PORT_RANGE_START` / `PORT_RANGE_END`
- `RUNNER_HOST`
- `RUNNER_ID`
- `CENTRAL_API_URL`

**Conservées (encore utilisées par weekly runs, archive, ou logs) :**
- `ARCHIPELAGO_GENERATE_IMAGE` - `DockerWeeklyRunGenerator`
- `DOCKER_HOST` - `DockerSocketClient` pour weekly runs
- `WORKSPACE_DIR` - weekly runs
- `ARCHIVE_DIR` - `ArchiveRunJobHandler`
- `RUNNER_API_KEY` - `HttpWeeklyRunnerGateway::launchFromSeed()` (gap connu)

### AC10 - Classes supprimées / infrastructure nettoyée

| Classe | Sort |
|---|---|
| `GenerateRunJobHandler` | Supprimé |
| `StartRunJobHandler` | Supprimé |
| `StopRunJobHandler` | Supprimé |
| `RestartRunJobHandler` | Supprimé |
| `RunHealthCheckJobHandler` | Supprimé |
| `GenerateRunJob` (message) | Supprimé |
| `StartRunJob` (message) | Supprimé |
| `StopRunJob` (message) | Supprimé |
| `RestartRunJob` (message) | Supprimé |
| `RunHealthCheckJob` (message) | Supprimé |
| `RunnerCallbackClient` | Supprimé si `ArchiveRunJobHandler` et `FetchLogsJobHandler` n'en ont plus besoin - sinon conservé |
| `PortPool` | Supprimé |

### AC11 - Quality gates verts

`phpstan`, `php-cs-fixer`, `phpunit`, `app:architecture:ddd` - 0 erreurs.

---

## Known gaps (hors scope)

### `DockerWeeklyRunGenerator` - weekly runs
Le flux weekly runs passe encore par Docker local (`DockerWeeklyRunGenerator`) et
`HttpWeeklyRunnerGateway::launchFromSeed()` (gap déjà documenté en 25.1). À traiter
dans une story dédiée.

### `ArchiveRunJobHandler` / `FetchLogsJobHandler`
Ces handlers utilisent encore `DockerSocketClient` ou `RunnerCallbackClient`. Hors scope -
ils continueront de fonctionner en parallèle du nouveau webhook controller.

---

## Tasks

- [ ] T1 : Lire `SessionLifecycleManager` - identifier où et comment stocker les credentials pending (AC3)
- [ ] T2 : Ajouter `storePendingCredentials()` à `SessionLifecycleManager`
- [ ] T3 : Réécrire `orchestrateValidate()` - `configure()` en lieu et place du dispatch Messenger
- [ ] T4 : Réécrire `orchestrateGenerate()` - `generate()` en lieu et place du dispatch Messenger
- [ ] T5 : Réécrire `orchestrateLaunch()` - `launch()` en lieu et place du dispatch Messenger
- [ ] T6 : Réécrire `orchestrateStop()` - `stop()` direct
- [ ] T7 : Réécrire `orchestrateRestart()` - `restart()` direct
- [ ] T8 : Créer `OrchestratorWebhookController`
- [ ] T9 : Alléger `RunnerCallbackController` (AC8)
- [ ] T10 : Supprimer les 5 handlers + 5 messages
- [ ] T11 : Supprimer `PortPool` + `RunnerCallbackClient` (si possible)
- [ ] T12 : Nettoyer `services.yaml` et `.env`
- [ ] T13 : Quality gates
