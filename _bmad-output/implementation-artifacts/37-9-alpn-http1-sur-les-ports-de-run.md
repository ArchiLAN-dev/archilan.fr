# Story 37.9: ALPN limité à `http/1.1` sur les ports de run (Firefox)

**Status:** review - reste la Task 5 (mesure après déploiement, retour Firefox)
**Epic:** 37 - Accès WSS aux serveurs Archipelago
**Date:** 2026-10-01
**Issue:** #525
**Dépend de :** 37.1, 37.2, 37.8 (livrées)

## Story

En tant que joueur qui utilise un client web Archipelago dans Firefox,
je veux pouvoir rejoindre une run en `wss://`,
afin de ne plus être bloqué sur une connexion impossible alors que Chrome et Opera passent.

## Contexte

L'issue #525 (2026-08-17) : les clients web qui exigent TLS fonctionnent sous Chrome et Opera, pas sous Firefox
(Nightly au moins), avec une « connexion impossible avec le serveur comme si le TLS posait toujours problème ».

### Cause, mesurée en production le 2026-10-01

Les routeurs TLS des runs ne déclarent aucune option TLS : Traefik leur applique ses options par défaut, qui
annoncent **`h2`** dans la négociation ALPN. Mesure sur `archilan.fr:35000` et `:35001`, deux runs réellement en
cours (le serveur Archipelago répond `426 Upgrade Required` à un `GET` en HTTP/1.1) :

```
openssl s_client -connect archilan.fr:35000 -servername archilan.fr -alpn h2,http/1.1
ALPN protocol: h2
```

- **Chrome** n'annonce que `http/1.1` sur une connexion WebSocket : il obtient `http/1.1`, l'upgrade passe.
- **Firefox** sait faire du WebSocket sur HTTP/2 (RFC 8441) et annonce aussi `h2` : il obtient `h2` et parle HTTP/2.
  Mais le routeur est un routeur **TCP** : Traefik termine le TLS et relaie les octets tels quels au serveur
  Archipelago, qui ne comprend que HTTP/1.1. La connexion échoue.

Les ports de run ne portent que du WebSocket vers Archipelago : n'y annoncer que `http/1.1` est la seule
négociation correcte. Le reste du proxy (le site, l'API) n'est pas concerné et garde HTTP/2 : l'option est
déclarée dans la configuration dynamique des runs et n'est référencée que par leurs routeurs.

## Critères d'acceptation

1. La configuration produite par `TraefikConfigBuilder::build()` déclare une option TLS `ap-runs` dont
   `alpnProtocols` vaut exactement `["http/1.1"]`, qu'il y ait des runs en cours ou non.
2. Le routeur TLS de chaque run la référence (`tls.options = "ap-runs"`). Certresolver, `domains`, entrypoint et
   service sont inchangés.
3. Le routeur en clair (37.8) reste sans bloc `tls`.
4. **Vérifié sur un banc Traefik local** de la même majeure que la production : avec la configuration produite,
   un client qui annonce `h2,http/1.1` obtient `http/1.1`, et un upgrade WebSocket aboutit toujours en `wss://`
   et en `ws://`.
5. **Après déploiement**, la même mesure `openssl s_client ... -alpn h2,http/1.1` sur un port de run en cours
   renvoie `ALPN protocol: http/1.1` ; l'issue #525 est confirmée corrigée sous Firefox par un joueur avant
   d'être fermée.
6. `docs/traefik-runs-archipelago.md` documente le piège et la mesure de diagnostic.
7. `composer gates` passe.

## Tasks / Subtasks

- [x] **Task 1** (AC 1-3) - Tests unitaires puis option `ap-runs` et sa référence dans `TraefikConfigBuilder`.
- [x] **Task 2** (AC 4) - Banc Traefik local.
- [x] **Task 3** (AC 6) - Documentation.
- [x] **Task 4** (AC 7) - Gates.
- [ ] **Task 5** (AC 5) - Après déploiement : mesure en production et retour d'un joueur Firefox.

## Notes techniques

- La configuration dynamique vient du provider HTTP, repollé toutes les 5 s : aucun redémarrage du proxy,
  effet sur les runs déjà en cours, retour arrière par revert de l'API.
- Le nom `ap-runs` est résolu dans le provider qui le déclare (`ap-runs@http`) : pas de collision avec les
  options des autres projets du proxy.

## Dev Agent Record

### TDD

| Test | Rouge avant | Vert après |
|------|-------------|------------|
| `testRunPortsOnlyNegotiateHttp1EvenWithoutRunningSession` | pas de bloc `tls` | option `ap-runs` toujours déclarée |
| `testTheTlsRouterUsesTheRunTlsOptions` | pas de `tls.options` | routeur `run-` référence `ap-runs`, certresolver et `domains` inchangés |

Les 11 tests existants de `TraefikConfigBuilderTest` passent sans modification (dont l'absence de bloc `tls`
sur le routeur clair).

### Banc local (AC 4, 2026-10-01)

`traefik:v3.6` en provider file, entrypoint `ap-35042`, routeurs `run-` (TLS) et `plain-` vers un backend qui ne
parle que HTTP/1.1 (upgrade WebSocket -> 101, préface HTTP/2 -> 400), client qui annonce `h2,http/1.1` :

| Configuration | ALPN négocié | Upgrade `wss` | Upgrade `ws` |
|---|---|---|---|
| sans option (état actuel en production) | `h2` | 101 | - |
| avec `ap-runs` | `http/1.1` | 101 | 101 |

Aucun avertissement ni erreur dans les journaux de Traefik. Une préface HTTP/2 (ce qu'envoie Firefox quand il
obtient `h2`) est rejetée par le backend HTTP/1.1 : c'est le mécanisme de #525.

### Gates

- `composer gates` : OK (2552 tests).
