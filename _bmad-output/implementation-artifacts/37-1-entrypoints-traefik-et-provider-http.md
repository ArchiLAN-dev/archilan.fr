# Story 37.1: Entrypoints Traefik et provider HTTP

**Status:** ready-for-dev
**Epic:** 37 - Accès WSS aux serveurs Archipelago
**Date:** 2026-08-10
**Dépend de :** rien techniquement. À livrer **en même temps que 37.2 et 37.3** : les trois ne
produisent un run joignable que complètes.

## Story

En tant qu'opérateur de la plateforme,
je veux que Traefik écoute sur toute la plage de ports des serveurs Archipelago et consomme la
configuration dynamique produite par l'API,
afin que les routeurs générés par 37.2 soient réellement appliqués au lieu de partir dans le vide.

## Context

La story 9.11 a livré la moitié du chemin : `TraefikConfigBuilder` produit une configuration de
routeurs et `GET /api/v1/internal/traefik` l'expose, authentifiée par `X-Traefik-Token`. Mais
`traefik/traefik.yml:16-22` ne déclare que les providers `docker` et `file` : **personne ne
consomme cette configuration**. Et Traefik n'écoute sur aucun des ports où tournent les serveurs
Archipelago.

Cette story rebranche les deux bouts. Elle n'invente rien côté API : l'endpoint et son
authentification existent et restent valides.

### Correction de cadrage : la plage de ports n'est pas celle annoncée par l'epic

L'epic parle de la plage `PORT_RANGE_START`-`PORT_RANGE_END` (`25000-25099`). **C'est la plage du
pool de ports du bridge, pas celle des serveurs Archipelago.** Vérifié dans le code le 2026-08-10 :

```go
// orchestrateur/internal/service/session.go:430-434
bridgePort, err := s.pool.Acquire(req.SessionID)
apPort := bridgePort + s.cfg.APServerPortOffset
```

avec `AP_SERVER_PORT_OFFSET=10000` (`orchestrateur/internal/config/config.go:49`,
`.env.prod.example:55`, `envs/orchestrateur.env.example:20`).

**La plage à ouvrir est donc `35000-35099`**, soit `[PORT_RANGE_START + offset,
PORT_RANGE_END + offset]`. Une génération d'entrypoints sur `25000-25099` ouvrirait cent ports sans
aucun serveur derrière, et laisserait les vrais serveurs injoignables.

Corollaire pour 37.6 : le banc de test doit occuper un port de la plage **AP**, pas du pool.

## Acceptance Criteria

### Génération de la configuration

1. Les entrypoints Traefik sont **générés** depuis `PORT_RANGE_START`, `PORT_RANGE_END` et
   `AP_SERVER_PORT_OFFSET` par un script versionné, **jamais écrits à la main**. Le fichier généré
   est commité ; le script est rejouable et idempotent.
2. Rejouer la génération sur une configuration à jour ne produit **aucun diff**. Une commande de
   vérification existe et est documentée (elle pourra devenir un job CI, hors périmètre ici).
3. Le nom d'un entrypoint est **dérivé du port** et déterministe, du même modèle que celui que 37.2
   utilisera côté routeur. La convention est écrite noir sur blanc dans la story et dans le script,
   parce que 37.2 en dépend et qu'aucun test ne relie les deux dépôts.
4. La publication des ports du conteneur Traefik (`traefik/docker-compose.yml`) couvre la même
   plage. Déclarer l'entrypoint sans publier le port ne produit **rien** : le conteneur n'écoute pas
   sur l'hôte.

### Provider HTTP

5. `traefik/traefik.yml` déclare un bloc `providers.http` pointant sur
   `https://{API_DOMAIN}/api/v1/internal/traefik`, avec le token dans l'en-tête `X-Traefik-Token`
   (l'option `headers` du provider HTTP existe en Traefik v3.3, vérifiée le 2026-08-10).
6. Le token n'est **pas en clair dans un fichier commité**. Il arrive par variable d'environnement,
   comme `ACME_EMAIL` et les clés OVH le font déjà dans `traefik/docker-compose.yml:12-17`.
7. `pollInterval` est fixé explicitement et justifié : c'est le délai maximum entre le passage d'une
   session à `running` et le moment où elle devient joignable.
8. Une réponse en erreur ou un token invalide **ne vide pas** la configuration en place : le
   comportement observé de Traefik dans ce cas est consigné dans la story (test réel : couper l'API,
   observer les routeurs existants).

### Validation en conditions réelles

9. Traefik redémarre proprement avec la plage complète déclarée, et le temps de démarrage ainsi que
   la consommation mémoire avant/après sont relevés. Cent entrypoints, ce n'est pas gratuit.
10. Le site, l'API, Mercure, MinIO et l'orchestrateur répondent toujours après le redémarrage. La
    plage ajoutée ne doit rien casser de l'existant.
11. **Le maintien d'une connexion longue est vérifié, pas supposé** (risque nommé par l'epic). Une
    connexion WebSocket réelle vers un serveur Archipelago traverse Traefik et reste établie
    **au moins une heure**, dont une période d'inactivité complète d'au moins 20 minutes. Le
    résultat est consigné, y compris s'il est bon.
12. Si un réglage de timeout s'avère nécessaire, il est appliqué sur l'entrypoint et documenté avec
    la valeur retenue et pourquoi.

## Tasks / Subtasks

- [ ] **Task 1** (AC 1-3). Écrire le script de génération des entrypoints et la convention de
  nommage. Générer, commiter le résultat.
- [ ] **Task 2** (AC 4). Publier la plage de ports sur le conteneur Traefik.
- [ ] **Task 3** (AC 5-7). Ajouter `providers.http`, l'injection du token par l'environnement, le
  `pollInterval`.
- [ ] **Task 4** (AC 8). Observer le comportement en cas d'API indisponible ou de token refusé.
- [ ] **Task 5** (AC 9-10). Redémarrage réel, relevés, contrôle de non-régression des services
  existants.
- [ ] **Task 6** (AC 11-12). Test de connexion longue, ajustement de timeout si nécessaire.

## Dev Notes

- **Compose accepte une plage de ports en une ligne** (`"35000-35099:35000-35099"`), là où Traefik
  exige cent déclarations d'entrypoints - la configuration statique n'a pas de notion de plage.
  C'est asymétrique et c'est normal ; le script ne génère que le côté Traefik, mais la story doit
  vérifier que les deux couvrent bien le même intervalle.
- **Une centaine de ports publiés, c'est une centaine de règles à installer au démarrage.** D'où
  l'AC 9 : mesurer, pas supposer. Si le coût est réel, l'alternative (mode `network_mode: host`
  pour Traefik) change beaucoup de choses par ailleurs et **n'entre pas dans cette story** - la
  constater et l'écrire suffit.
- **Le plafond de runs devient rigide.** Élargir le pool imposera de régénérer les entrypoints et de
  redémarrer Traefik, ce qui coupe tout le site. L'epic l'assume ; le script doit rendre l'opération
  triviale, et le README ou l'en-tête du script doit dire explicitement « redémarrage de Traefik
  requis ».
- **Le redémarrage de Traefik a un rayon d'action large** : il porte le site, l'API, Mercure, MinIO
  et l'orchestrateur (`docker-compose.prod.yml`). Créneau calme, et si 37.3 est déjà livrée, toutes
  les parties Archipelago en cours tombent aussi.
- **Ne pas toucher à l'endpoint ni à son authentification.** `TraefikConfigController` et
  `TraefikAndPublisherTokenTest` sont valides et restent tels quels dans cette story. Ce qui change
  dans le contenu de la réponse appartient à 37.2.

### Project Structure Notes

- `traefik/traefik.yml` : entrypoints générés + bloc `providers.http`.
- `traefik/docker-compose.yml` : publication de la plage, variable d'environnement du token.
- Script de génération : `scripts/` (déjà occupé par `setup-worktree.sh` et `transfer-volumes.sh`,
  tous deux en shell - suivre cette forme).
- Aucun code `api/` ni `frontend/` n'est touché : `composer gates` et `pnpm gates` ne sont pas
  concernées par cette story.

### References

- [Source: _bmad-output/planning-artifacts/epics/epic-37-acces-wss-aux-serveurs-archipelago.md]
- [Source: traefik/traefik.yml] - providers actuels, resolver letsencrypt DNS-01 OVH
- [Source: traefik/docker-compose.yml] - ports publiés aujourd'hui (80, 443, 8080), injection des secrets
- [Source: orchestrateur/internal/service/session.go:430-434] - `apPort = bridgePort + offset`
- [Source: orchestrateur/internal/config/config.go:47-49] - `PORT_RANGE_*`, `AP_SERVER_PORT_OFFSET`
- [Source: api/src/Sessions/Presentation/Controller/TraefikConfigController.php] - endpoint et token

## Dev Agent Record

### Agent Model Used

### Completion Notes List

### File List

### Change Log

| Date | Change |
|------|--------|
| 2026-08-10 | Créée. Corrige la plage de ports annoncée par l'epic : les serveurs AP sont sur `35000-35099` (pool + `AP_SERVER_PORT_OFFSET`), pas sur le pool lui-même. |
