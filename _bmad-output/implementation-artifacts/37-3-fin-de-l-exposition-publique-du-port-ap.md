# Story 37.3: Fin de l'exposition publique du port Archipelago

**Status:** ready-for-dev
**Epic:** 37 - Accès WSS aux serveurs Archipelago
**Date:** 2026-08-10
**Dépend de :** 37.1 et 37.2. **Ne jamais déployer seule** : sans routeur Traefik en face, refermer
le port rend toutes les runs injoignables.

## Story

En tant que responsable de la plateforme,
je veux que le serveur Archipelago d'une run ne soit plus publié en clair sur l'hôte,
afin que Traefik devienne la seule socket publique du run et que le trafic joueur soit chiffré de
bout en bout.

## Context

Chaque run publie aujourd'hui son serveur Archipelago en clair sur `0.0.0.0` :

```go
// orchestrateur/internal/docker/client.go:782-786
ExposedPorts: map[string]struct{}{"38281/tcp": {}},
HostConfig: apServerHostConfig{
    PortBindings: map[string][]portBinding{
        "38281/tcp": {{HostIP: "0.0.0.0", HostPort: fmt.Sprintf("%d", cfg.APPort)}},
    },
```

C'est la seule liaison hôte du port Archipelago dans tout le système (vérifié le 2026-08-10). Une
fois 37.1 et 37.2 en place, elle fait doublon avec le chemin TLS et laisse ouvert un accès en clair
que rien ne justifie plus.

### Correction de cadrage : ce n'est pas `runner/app/docker_manager.py`

L'epic désigne `runner/app/docker_manager.py:104`. **Ce n'est pas le composant déployé.** La
production fait tourner l'orchestrateur Go (`ghcr.io/archilan-dev/orchestrateur`,
`docker-compose.prod.yml:159-161`), et c'est lui qui crée le conteneur du serveur Archipelago, dans
`orchestrateur/internal/docker/client.go`, fonction `CreateAPServer`. Le runner Python n'apparaît
dans aucun service de la compose de production.

**Conséquence pratique : cette story se code dans le dépôt `orchestrateur`, pas dans le monorepo.**
Elle a donc sa propre branche, sa propre PR et sa propre CI, et son déploiement est une **publication
d'image**, pas un simple `git pull`. Le fichier de story reste ici, mais le code part ailleurs
(voir la topologie des dépôts : `bridge`, `orchestrateur` et `archipelago` sont des dépôts séparés).

### Ce qui rend le retrait possible

Le conteneur est déjà attaché à un réseau Docker (`BRIDGE_NETWORK`, valeur de production
`archilan-prod_default`) et joignable sous le nom `ap-server-{sessionId}` sur le port `38281`.
Deux consommateurs internes s'en servent déjà et ne passent **pas** par le port hôte :

- la sonde de démarrage de l'orchestrateur (`internal/service/session.go:498`) ;
- le bridge, qui reçoit `AP_WS_URL=ws://ap-server-{sessionId}:38281` (`client.go:139`).

L'option « binder sur `127.0.0.1` » évoquée par l'epic est donc **à écarter, et pas seulement par
préférence** : un service lié à la loopback de l'hôte n'est pas joignable depuis un conteneur, donc
Traefik ne pourrait plus l'atteindre. Le partage de réseau est la seule des deux options qui marche.

## Acceptance Criteria

### Retrait de la liaison hôte

1. `CreateAPServer` ne publie plus `38281/tcp` sur l'hôte. Après lancement d'une run, `docker ps`
   n'affiche plus de mapping de port pour le conteneur `ap-server-*`, et `ss -lntp` ne montre plus
   de socket d'écoute sur le port AP de la run.
2. Le conteneur reste joignable en interne sous `ap-server-{sessionId}:38281` : la sonde de démarrage
   et le bridge fonctionnent sans modification.
3. Le port AP **continue d'être alloué et stocké** (`apPort = bridgePort + AP_SERVER_PORT_OFFSET`,
   webhook `session.ready`, colonnes de session). Il ne désigne plus un port hôte mais **le port
   public servi par Traefik** ; c'est lui qui identifie le run et sélectionne l'entrypoint.

### Chemin réseau vers Traefik

4. Traefik et le conteneur du serveur Archipelago partagent un réseau. L'option retenue est
   documentée dans la story avec sa raison. Recommandation : **attacher le conteneur de session au
   réseau `archilan-proxy`** en plus de son réseau applicatif, plutôt que de déplacer Traefik dans le
   réseau applicatif - Traefik y verrait tous les services internes sans nécessité.
5. Le nom du réseau est **configurable par variable d'environnement**, comme `BRIDGE_NETWORK`, et
   documenté dans `envs/orchestrateur.env.example`. Pas de nom de réseau en dur.
6. Le chemin est vérifié en conditions réelles : depuis le conteneur Traefik, une connexion TCP vers
   `ap-server-{sessionId}:38281` aboutit.

### Non-régression

7. Le cycle de vie complet d'une run passe : lancement, connexion d'un client, mise en pause,
   reprise depuis sauvegarde, arrêt. La reprise passe par le même chemin de création de conteneur et
   doit donc être testée explicitement, pas déduite du lancement.
8. Aucun autre composant ne dépendait du port hôte. Le contrôle est fait, pas supposé : la variable
   `AP_SERVER_HOST_PORT` transmise au conteneur bridge (`client.go:143`) **n'est lue nulle part dans
   `bridge/`** (vérifié le 2026-08-10) - la retirer, ou écrire pourquoi elle reste.
9. **Le port du bridge reste publié sur l'hôte** et n'entre pas dans le périmètre : l'API le joint
   par port hôte. C'est une seconde exposition, réelle et assumée ici, à traiter ailleurs. La story
   ne doit pas laisser croire qu'elle referme toute la surface publique d'un run.
10. Les tests Go du dépôt orchestrateur passent (`internal/service/session_test.go` et voisins).

### Déploiement

11. L'ordre de mise en production est écrit et respecté : **37.1 et 37.2 d'abord, vérifiées sur une
    run réelle joignable en `wss://`, puis 37.3**. L'inverse coupe toutes les runs.
12. Le retour arrière est documenté : redéployer l'image précédente de l'orchestrateur restaure la
    publication du port. Le savoir avant, pas pendant.

## Tasks / Subtasks

- [x] **Task 1** (AC 1-3). Retirer `PortBindings` (et `ExposedPorts` si plus utile) de
  `CreateAPServer`.
- [x] **Task 2** (AC 4-6). Attacher le conteneur au réseau du proxy, variable d'environnement
  dédiée. *La vérification du chemin depuis Traefik reste à faire en production.*
- [ ] **Task 3** (AC 8). Traiter `AP_SERVER_HOST_PORT` : retrait ou justification écrite.
  *Reporté : voir les notes.*
- [ ] **Task 4** (AC 7, 10). Cycle de vie complet sur une run réelle **(production)**. Tests Go
  verts : fait.
- [x] **Task 5** (AC 11-12). Écrire la procédure de déploiement et de retour arrière.

## Dev Notes

- **La sonde de démarrage est déjà correcte** : elle vise l'adresse interne parce que
  l'orchestrateur tourne lui-même dans un conteneur (commentaire à `session.go:496-497`). Rien à y
  changer, et c'est la preuve que le chemin interne fonctionne.
- **Le port garde son sens, il change de nature.** Ne pas céder à la tentation de « simplifier » en
  supprimant l'allocation : c'est lui qui distingue deux runs derrière Traefik, et 37.2 en dépend
  pour choisir l'entrypoint.
- **Restart policy.** Le conteneur AP est en `on-failure` avec 3 tentatives (`client.go:788-791`).
  Un redémarrage recrée-t-il la configuration réseau attendue ? Le vérifier plutôt que le supposer,
  en tuant le processus dans le conteneur et en observant si Traefik le rejoint toujours.
- **Un run devient injoignable si le provider HTTP est en panne** au moment où la session passe à
  `running` : avant, le port hôte était ouvert quoi qu'il arrive. C'est un nouveau point de
  défaillance unique, à assumer et à surveiller (voir AC 7 de 37.1 sur le `pollInterval` et AC 8 sur
  le comportement en cas d'API muette).
- **Sécurité : ce que la story ferme et ce qu'elle ne ferme pas.** Elle ferme le trafic joueur en
  clair. Elle ne ferme ni le port du bridge, ni l'absence de contrôle d'origine sur les WebSockets
  (le mot de passe Archipelago reste la seule barrière réelle), ni l'énumérabilité d'une plage de
  ports bornée sur un hôte connu. Ces trois points sont nommés dans l'epic et restent ouverts.

### Project Structure Notes

- Dépôt **orchestrateur** (séparé du monorepo, non versionné ici) :
  `internal/docker/client.go` (`CreateAPServer`, `createAPServerBody`, `apServerHostConfig`),
  `internal/config/config.go` (nouvelle variable de réseau), `envs/orchestrateur.env.example`.
- Monorepo : rien à modifier côté `api/` ni `frontend/`. `composer gates` et `pnpm gates` ne sont pas
  concernées ; la CI qui compte est celle du dépôt orchestrateur.
- `runner/app/docker_manager.py` n'est **pas** modifié par cette story - ce n'est pas le composant
  déployé. Si le runner Python est encore utilisé quelque part, c'est un sujet distinct à lever
  avant de commencer.

### References

- [Source: _bmad-output/planning-artifacts/epics/epic-37-acces-wss-aux-serveurs-archipelago.md]
- [Source: orchestrateur/internal/docker/client.go:782-793] - publication du port à retirer, réseau du conteneur
- [Source: orchestrateur/internal/docker/client.go:139-143] - `AP_WS_URL` interne, `AP_SERVER_HOST_PORT` inutilisé
- [Source: orchestrateur/internal/service/session.go:496-499] - sonde sur l'adresse interne
- [Source: docker-compose.prod.yml:159-180] - service orchestrateur déployé, réseaux `default` et `archilan-proxy`
- [Source: bridge/adapters/docker_runtime.py:64-67] - le bridge résout le conteneur AP par son nom

## Dev Agent Record

### Agent Model Used

claude-opus-5[1m]

### Completion Notes List

Code livré dans le dépôt orchestrateur : **PR ArchiLAN-dev/archilan-orchestrateur#18**, branche
`feature/epic-37-story-3-fin-exposition-port-ap`. Le présent dépôt ne porte que la configuration
d'exemple et cette story.

**Deux modes explicites plutôt qu'un retrait sec.** `AP_PUBLISH_HOST_PORT` (défaut `true`) et
`PROXY_NETWORK` (défaut vide). Raison : le développement local n'a **pas** de reverse proxy - la
compose de dev ne déclare ni Traefik ni réseau `archilan-proxy`. Retirer la publication sans
condition aurait rendu toute run locale injoignable. La production met `false` + `archilan-proxy`.

**Les deux désactivés à la fois sont refusés au démarrage.** Sans port hôte et sans réseau proxy,
chaque run serait injoignable **tout en paraissant saine** : la session passe `running`, le webhook
part, l'UI affiche une adresse, et personne ne peut se connecter. Aucun symptôme jusqu'à ce qu'un
joueur se plaigne. D'où l'échec au démarrage.

**Le connect réseau est un second appel API, pas un champ de création.** Attacher plusieurs réseaux
dans un seul `/containers/create` n'est supporté que par les API Docker récentes ; ce code ne doit
pas dépendre de la version du démon de la machine de prod. Si le connect échoue, le conteneur est
supprimé et le lancement échoue bruyamment.

**AC 8, deuxième moitié, non traitée.** Le contrôle « aucun autre composant ne dépend du port
hôte » est fait : la sonde de démarrage et le bridge passent tous deux par le nom de conteneur, et
`AP_SERVER_HOST_PORT` n'est lu nulle part dans `bridge/`. En revanche cette variable morte **n'a
pas été retirée** : elle est transmise au conteneur bridge, la supprimer touche un autre composant
que celui de cette story, et un retrait à l'aveugle sur un composant non testé ici est un risque
gratuit. À traiter dans un ticket de nettoyage propre au bridge.

**Restent à faire, en production uniquement** : le chemin réseau Traefik vers
`ap-server-{sessionId}:38281` (AC 6), le cycle de vie complet dont la reprise depuis sauvegarde
(AC 7), et le respect de l'ordre de déploiement (AC 11).

### File List

Dépôt `orchestrateur` (PR #18) :

- `internal/config/config.go` - `ProxyNetwork`, `PublishAPPort`, `Validate()`
- `internal/config/config_test.go` (nouveau)
- `internal/docker/client.go` - `apServerPortBindings`, `connectNetwork`, champs de config
- `internal/docker/ap_server_test.go` (nouveau)
- `internal/service/session.go` - passage des deux nouveaux réglages
- `.env.example`

Ce dépôt :

- `envs/orchestrateur.env.example` - configuration de production

### Change Log

| Date | Change |
|------|--------|
| 2026-08-11 | Implémentation (orchestrateur PR #18) : publication du port conditionnée, attachement au réseau du proxy après création, refus au démarrage de la combinaison injoignable. Le dev local garde la publication, faute de proxy. |
| 2026-08-10 | Créée. Corrige la cible de l'epic : le composant déployé est l'orchestrateur Go (`CreateAPServer`), pas le runner Python. L'option « binder sur 127.0.0.1 » est écartée sur argument technique (inatteignable depuis le conteneur Traefik). |
