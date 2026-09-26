# Story 17.26: Cohérence des ports attribués aux sessions

**Status:** review
**Epic:** 17 - Cycle de vie des sessions (inactivité, relance)
**Date:** 2026-09-26
**Origine :** signalement de Jean le 2026-09-26 - le même port semble attribué à deux sessions différentes, et une
weekly arrêtée puis relancée le lendemain affiche peut-être encore son ancien port.

## Story

En tant que joueur d'une run privée, d'une run hebdo ou d'un event,
je veux que l'adresse de connexion affichée soit toujours celle du serveur qui tourne pour ma partie,
afin de ne jamais me connecter au serveur d'une autre session.

## Contexte (revue du 2026-09-26)

L'orchestrateur n'attribue jamais le même port à deux sessions vivantes : le pool est protégé par un mutex et les
libérations vérifient le propriétaire. Chaque lancement ou relance prend un **nouveau** port de bridge, et le port AP
en dérive (`bridge + 10000`). Le doublon observé vient de l'API, qui garde et ré-affiche des ports périmés :

| # | Défaut | Effet |
|---|---|---|
| 1 | `weekly_entries.connection_port` n'est écrit qu'au premier lancement, et la liste hebdo (`DbalCurrentWeeklyRunsQuery`) le lit au lieu de la session | après une relance, le joueur voit l'ancien port ; si l'orchestrateur l'a redonné à une autre session, il se connecte chez quelqu'un d'autre |
| 2 | une session arrêtée, en veille ou plantée garde son dernier port ; `SessionQuery` (`connectionUri`) et `Session::payload()` (Mercure, connexion joueur d'event) l'exposent sans regarder le statut | adresse périmée servie comme valide |
| 3 | `recordRestarted` retombe sur l'ancien port quand le port reçu est absent ou nul, garde l'ancien port de bridge, et ignore un nouveau port si la session tourne déjà | ancien port réécrit comme port courant |
| 4 | `session.ready` sans port sur une session en veille ou arrêtée : la transition intermédiaire est écrite, puis `running` échoue ; le webhook répond 200 | session bloquée en `restarting` / `launching` |

Côté orchestrateur (dépôt séparé) :

| # | Défaut | Effet |
|---|---|---|
| 5 | au démarrage, les ports sont réservés (`RecoverFromDB`) **avant** que les lancements interrompus soient marqués plantés (`CrashAllTransitSessions`, lancé par le sweeper), et leurs conteneurs ne sont pas arrêtés | port perdu jusqu'au prochain redémarrage ; un conteneur orphelin peut garder le port hôte lié |
| 6 | `AllSessionPorts` réserve toute session non `stopped`/`crashed`, y compris une session remise en `generated` avec son ancien port | vole la propriété du port d'une session vivante, qui ne peut plus le libérer |

## Critères d'acceptation

1. **Hebdo : la session fait foi.** `connectionInfo` (ma participation et participants) prend hôte et port de la
   session de la participation quand elle existe ; les colonnes de `weekly_entries` ne servent plus que pour une
   participation sans session (antérieure à 17.13). Après une relance sur un nouveau port, la liste affiche le
   nouveau port. Le front garde son contrat : `connectionInfo` non nul dès que la participation est lancée, et
   l'affichage reste piloté par `sessionStatus`.
2. **Session : pas d'adresse hors `running`.** `SessionQuery::findById` et `Session::payload()` renvoient `host`,
   `port` et `connectionUri` nuls quand la session ne tourne pas. Le port de bridge reste exposé (usage interne).
3. **`recordRestarted`** : un port absent ou nul est refusé (`invalid_endpoint`, 422 sur `/restarted`, journalisé),
   jamais remplacé par l'ancien ; un hôte vide prend l'hôte public configuré ; un port de bridge absent vaut `null`,
   comme sur `session.ready`. Une session déjà `running` adopte le nouveau point d'accès, comme `session.ready`.
4. **`session.ready` sans port** : refusé avant toute écriture (la session garde son statut), 422 `invalid_port`,
   journalisé.
5. **Orchestrateur** : au démarrage, les sessions `generating` / `launching` sont d'abord marquées plantées (leurs
   conteneurs arrêtés par nom), puis seuls les ports des sessions `running` sont réservés.
6. `composer gates`, `pnpm gates` (front non touché) et `go test ./...` passent.

## Ordre TDD

- `CurrentWeeklyRunsTest` : participation relancée (session sur un nouveau port) → nouveau port ; participation sans
  session → colonnes de l'entrée.
- `SessionLiveEndpointTest` (unit) : adresse exposée seulement en `running` ; `payload()` idem.
- `SessionRestartTest` : port nul → 422 et session inchangée ; bridge absent → `null` ; session `running` → adopte le
  nouveau port.
- `OrchestratorWebhookTest` : `session.ready` sans port sur une session en veille → 422, reste `idle`.
- Orchestrateur `service` : `RecoverFromDB` plante les lancements interrompus avant de réserver, ne réserve pas une
  session `generated` au port périmé.

## Tasks / Subtasks

- [x] **Task 1** (AC 1) - Liste hebdo lue depuis la session.
- [x] **Task 2** (AC 2) - Adresse exposée seulement en `running`.
- [x] **Task 3** (AC 3, 4) - Relance et `session.ready` sans retombée sur l'ancien port.
- [x] **Task 4** (AC 5) - Orchestrateur : ordre de reprise au démarrage.
- [x] **Task 5** (AC 6) - Gates.

## Hors périmètre

- Renvoyer le mail « session prête » après une relance (le mail part une seule fois, `isNotified()`).
- Les `participants[].connectionInfo` de la liste hebdo portent le mot de passe de chaque participation, alors que
  la liste est publique et que le front ne s'en sert pas : à traiter dans une story dédiée.

## Dev Agent Record

- **Hebdo** : `DbalCurrentWeeklyRunsQuery` lit `COALESCE(s.host, we.connection_host)` et
  `COALESCE(s.port, we.connection_port)`. Les colonnes de la participation ne sont plus mises à jour : elles restent
  l'adresse du premier lancement, utile seulement pour une participation sans session. Pas de migration.
- **Session** : `Session::liveEndpoint()` (pur) renvoie hôte et port seulement en `running` ; `payload()` et
  `SessionQuery::findById` s'en servent. Le front masquait déjà ces champs hors `running` (admin, event, hebdo) :
  aucun changement côté front.
- **`recordRestarted`** : plus aucune retombée sur l'ancien point d'accès (hôte vide → hôte public, port absent →
  `invalid_endpoint` 422, bridge absent → `null`) ; une session déjà `running` adopte le nouveau port.
  `Session::resumeRunning` accepte un port de bridge nul.
- **`session.ready`** : le port est vérifié avant toute écriture ; 422 `invalid_port` (l'orchestrateur ne rejoue
  pas un webhook, il journalise le non-2xx).
- **Orchestrateur** (`feature/coherence-des-ports`) : `RecoverFromDB` plante d'abord les sessions interrompues
  (conteneurs de lancement arrêtés par nom via une interface `containerStopper`, webhook `session.crashed`), puis ne
  réserve que les ports des sessions `running`. Le sweeper ne fait plus la reprise au démarrage.

### Déroulé TDD

| Étape | Rouge | Vert |
|---|---|---|
| `CurrentWeeklyRunsTest` (participation relancée sur un nouveau port) | ancien port 35001 affiché | 35007 |
| `SessionLiveEndpointTest` (running, idle, stopped, crashed, restarting) | `liveEndpoint` absent, adresse exposée | verts |
| `SessionRestartTest` (port absent, bridge absent, hôte absent, session running) | 200 avec l'ancien port, bridge à 0, hôte périmé, nouveau port ignoré | verts |
| `OrchestratorWebhookTest` (`session.ready` sans port sur une session en veille) | 200, session bloquée en `restarting` | 422, reste `idle` |
| `recovery_test.go` (orchestrateur) | lancement interrompu resté `launching` et port retenu ; session `generated` propriétaire du port d'une session vivante | verts |