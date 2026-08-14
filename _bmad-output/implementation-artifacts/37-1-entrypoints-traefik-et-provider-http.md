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

## Correction majeure du 2026-08-13 : la story visait un proxy qui n'existe pas

Le répertoire `traefik/` de ce dépôt **n'a jamais été déployé**. Le reverse proxy réel sert
plusieurs projets de l'hôte, vit hors du dépôt, et est configuré **entièrement en arguments de
ligne de commande** - pas de fichier statique du tout. Tout ce que la story décrit ci-dessous à
propos de `traefik/traefik.yml` porte donc sur un artefact mort.

Ce que cela change, vérifié le 2026-08-13 :

| Hypothèse de la story | Réalité |
|---|---|
| Configuration par fichier statique | Arguments CLI, dans un compose hors dépôt |
| Traefik v3.3 | **v2.11** |
| Certresolver `letsencrypt` (DNS-01 OVH) | Certresolver `https` (TLS-ALPN sur 443) |
| Réseau `archilan-proxy` | Réseau du proxy, à confirmer sur l'hôte |

**Le point bloquant : l'option `headers` du provider HTTP n'existe qu'à partir de la v3.** En v2.11,
Traefik **refuse de démarrer** (`failed to decode configuration from flags: field not found, node:
headers`), vérifié le 2026-08-14. Le proxy portant tout le trafic entrant de l'hôte, une tentative
sur une v2 ne rend pas les runs injoignables : elle met l'hôte entier à terre. Il n'y a pas de
contournement propre : **le passage du proxy en v3 est un prérequis de la chaîne**, arbitré avec
Jean le 2026-08-13.

*Correction du 2026-08-14 : cette story a d'abord affirmé que l'option serait « ignorée sans
avertissement ». C'était une supposition, jamais testée, et fausse - dans le sens le plus coûteux.*

Décisions prises en conséquence : le proxy passe en v3 ; `traefik/` est **supprimé du dépôt** ; le
générateur produit désormais un **fragment à coller** dans le compose du proxy, où qu'il vive ; le
nom du certresolver devient configurable côté API. Le corollaire du `${ACME_EMAIL}` jamais
interpolé est enfin expliqué : personne n'a jamais lu ce fichier.

Ce qui reste valide de la story : la dérivation de la plage depuis `PORT_RANGE_*` +
`AP_SERVER_PORT_OFFSET`, la convention `ap-{port}`, l'exigence d'un `pollInterval` explicite, et
tous les ACs de validation en production.

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

- [x] **Task 1** (AC 1-3). Écrire le script de génération des entrypoints et la convention de
  nommage. Générer, commiter le résultat.
- [x] **Task 2** (AC 4). Publier la plage de ports sur le conteneur Traefik.
- [x] **Task 3** (AC 5-7). Ajouter `providers.http`, l'injection du token, le `pollInterval`.
- [ ] **Task 4** (AC 8). Observer le comportement en cas d'API indisponible ou de token refusé.
  **Exécution en production requise.**
- [ ] **Task 5** (AC 9-10). Redémarrage réel, relevés, contrôle de non-régression des services
  existants. **Exécution en production requise.**
- [ ] **Task 6** (AC 11-12). Test de connexion longue, ajustement de timeout si nécessaire.
  **Exécution en production requise.**

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
- **Conflit de ports avec l'ancien orchestrateur (constaté le 2026-08-13).** Publier la plage
  `35000-35099` sur le conteneur Traefik entre en collision frontale avec le comportement d'avant
  37.3, où chaque run publie son propre port dans cette plage. Si une run occupe un port au moment
  du redémarrage, **Traefik ne démarre pas** et tout le site tombe avec lui. Cette story ne peut
  donc pas être déployée avant le basculement de l'orchestrateur : voir l'ordre corrigé dans
  `traefik/README.md` et dans l'AC 11 de la story 37.3.
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

claude-opus-5[1m]

### Completion Notes List

**Découverte qui a décidé la forme de la solution : Traefik n'interpole aucune variable dans sa
configuration statique**, et fichier / arguments CLI / variables d'environnement sont trois sources
**mutuellement exclusives** (documentation v3.3, `getting-started/configuration-overview`). Deux
conséquences :

1. Le token du provider HTTP **ne peut pas** être injecté par variable d'environnement à côté d'un
   `traefik.yml` : les variables seraient purement ignorées. Il doit être substitué **avant** le
   démarrage. D'où le passage à `traefik.yml.tpl` (commité) + `traefik.yml` (généré, gitignoré,
   retiré du suivi git par `git rm --cached`).
2. **Le `${ACME_EMAIL}` présent dans `traefik/traefik.yml` depuis l'origine n'a jamais été
   substitué** : la chaîne littérale partait chez Let's Encrypt. À vérifier sur le serveur - si les
   certificats sont valides aujourd'hui, c'est que la copie de production a été éditée à la main et
   diverge du dépôt. **Comparer avant la première génération**, sinon le rendu écrase une
   configuration qui, elle, fonctionne.

**Écart assumé sur l'AC 5.** L'AC demandait un endpoint `https://{API_DOMAIN}/...`. L'implémentation
vise `http://archilan-api-web/api/v1/internal/traefik`, en interne sur `archilan-proxy`. Passer par
le nom public ferait dépendre Traefik de son propre routage pour aller chercher sa configuration -
dépendance circulaire au démarrage, et TLS inutile sur un réseau interne. La valeur reste
configurable (`TRAEFIK_HTTP_PROVIDER_ENDPOINT`).

**AC 11 (tenue d'une connexion longue) - mesure locale, 2026-08-13.** La chaîne complète a été
montée (configuration générée par le script, provider HTTP servant la réponse de l'API, routeur TCP,
backend joint par nom de conteneur), puis une connexion TLS a été établie sur un entrypoint `ap-` et
laissée **totalement inactive** : aucun octet dans aucun sens.

Résultat : **toujours ouverte après 31 minutes, et toujours utilisable** - une requête envoyée à la
fin a reçu sa réponse du backend (`HTTP/1.0 200 OK`), ce qui exclut le cas d'une socket morte côté
proxy mais encore ouverte côté client. Aucune mention de timeout dans les logs de Traefik.

Ce que ça règle : l'`idleTimeout` de 180 s, nommé par l'epic comme « le genre de défaut qui passe
les tests et casse en production au bout de trois minutes », **ne s'applique pas aux routeurs TCP**.
Le risque principal de l'architecture tombe.

Ce que ça ne règle pas, et qui reste à faire en production : la même mesure sur **une vraie partie
Archipelago d'au moins une heure**, derrière un certificat réel et avec les cent entrypoints
déclarés. Le test local porte sur une connexion, pas sur la charge.

**Vérifications effectuées en local :**

- rendu idempotent : deux générations successives, puis `--check` vert ;
- YAML valide relu par un parseur : 102 entrypoints (`web`, `websecure`, `ap-35000` .. `ap-35099`),
  `providers.http.headers`, email ACME et endpoint correctement substitués ;
- `docker compose config` valide sur la compose modifiée ;
- modes d'échec : dérive détectée par `--check` (sortie 1), token vide refusé, plage inversée
  refusée, garde-fou à 512 ports, valeur non numérique refusée ;
- **bug corrigé pendant le test** : depuis bash 5.2, un `&` dans la valeur de remplacement de
  `${var//motif/valeur}` désigne le texte apparié. Un token contenant `&` était silencieusement
  corrompu en `...${TRAEFIK_TOKEN}...`. La substitution se fait désormais par découpe explicite.
- le fichier d'environnement est lu **littéralement** (sémantique `env_file` de docker compose) et
  non sourcé : un token contenant `$`, `&` ou des espaces ne casse plus la lecture.

**Restent à faire, en production uniquement** (AC 8 à 12) : comportement quand l'API est muette ou
le token refusé, redémarrage réel avec relevés de démarrage et de mémoire, non-régression des
services existants, et surtout la **tenue d'une connexion longue d'au moins une heure**. Tant que
ces points ne sont pas faits, la story n'est pas complète, même si le code l'est.

### File List

- `scripts/gen-traefik-config.sh` (nouveau) - générateur, mode `--check`, garde-fous
- `traefik/traefik.yml.tpl` (nouveau) - source de la configuration statique
- `traefik/traefik.yml` - **retiré du suivi git**, désormais généré
- `traefik/docker-compose.yml` - bloc de ports entre marqueurs, commentaire sur le fichier généré
- `traefik/.env.example` - plage de ports et variables du provider HTTP
- `traefik/README.md` (nouveau) - procédure de génération, de déploiement et de vérification
- `.gitignore` - `traefik/traefik.yml`

### Change Log

| Date | Change |
|------|--------|
| 2026-08-13 | **Reimplementee sur l'infrastructure reelle.** Le proxy de production est en arguments CLI, en v2.11, avec le certresolver `https` : la generation d'un `traefik.yml` ne servait a rien. `traefik/` supprime du depot, `scripts/gen-traefik-config.sh` remplace par `scripts/gen-traefik-entrypoints.sh` qui imprime un fragment a coller, certresolver rendu configurable, documentation d'exploitation deplacee dans `docs/traefik-runs-archipelago.md`. Le passage du proxy en v3 devient un prerequis : l'option `headers` du provider HTTP n'existe pas en v2. |
| 2026-08-10 | Implémentation : générateur, template, provider HTTP, publication de la plage. Passage à un `traefik.yml` généré non commité, imposé par l'absence d'interpolation dans la configuration statique de Traefik. ACs de validation en production (8-12) non exécutés. |
| 2026-08-10 | Créée. Corrige la plage de ports annoncée par l'epic : les serveurs AP sont sur `35000-35099` (pool + `AP_SERVER_PORT_OFFSET`), pas sur le pool lui-même. |
