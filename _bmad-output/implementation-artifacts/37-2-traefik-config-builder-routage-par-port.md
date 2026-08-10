# Story 37.2: TraefikConfigBuilder - routage par port

**Status:** ready-for-dev
**Epic:** 37 - Accès WSS aux serveurs Archipelago
**Date:** 2026-08-10
**Dépend de :** 37.1 pour la convention de nommage des entrypoints. À livrer avec 37.1 et 37.3.

## Story

En tant que joueur,
je veux que le serveur Archipelago de ma run soit exposé en TLS sur son port, derrière un
certificat valide,
afin de pouvoir m'y connecter en `wss://` depuis un client desktop comme depuis une page web.

## Context

`TraefikConfigBuilder` (`api/src/Sessions/Application/Support/TraefikConfigBuilder.php`) génère
aujourd'hui, pour chaque session `running`, un routeur **HTTP** sur `{sessionId}.{WS_DOMAIN}`,
entrypoint `websecure`, avec `'tls' => new \stdClass()` - donc **sans certresolver**, ce qui ferait
servir le certificat auto-signé de Traefik et serait rejeté net par tout navigateur.

L'epic a tranché : **le port identifie le run**, le certificat est celui d'`archilan.fr` déjà émis,
et le sous-domaine par run disparaît. Cette story réécrit le générateur en conséquence.

### Correction de cadrage : le backend n'est pas `host:port`

Le builder cible aujourd'hui `http://{host}:{port}`, c'est-à-dire le port publié sur l'hôte. Or
le conteneur du serveur Archipelago est **déjà attaché à un réseau Docker** et joignable par son
nom - l'orchestrateur s'en sert lui-même pour sa sonde de démarrage :

```go
// orchestrateur/internal/service/session.go:498-499
apAddr := fmt.Sprintf("ap-server-%s:38281", sessionID)
if !s.docker.WaitForAddress(ctx, apAddr, 60*time.Second) {
```

Le nom du conteneur est `ap-server-{sessionId}` (`orchestrateur/internal/docker/client.go`,
`CreateAPServer`) et il écoute sur `38281/tcp` à l'intérieur.

**Viser `ap-server-{sessionId}:38281` plutôt que `{host}:{apPort}` est ce qui rend 37.3 possible** :
tant que le routeur passe par le port hôte, ce port ne peut pas être refermé. Le prix est que
Traefik doit partager un réseau avec les conteneurs de session, ce que 37.3 met en place.

## Acceptance Criteria

### Sortie du générateur

1. Pour chaque session `running` disposant d'un port, le builder produit **un routeur TCP** sur
   l'entrypoint correspondant à son port AP, selon la convention de nommage fixée par 37.1.
2. La règle est un SNI joker (`HostSNI` sur `*`) : le port identifie le run à lui seul, il n'y a
   qu'un backend derrière chaque entrypoint, donc **aucun démultiplexage par SNI n'est nécessaire**.
3. Le routeur porte une configuration TLS avec le **certresolver `letsencrypt`** et le domaine
   explicite `archilan.fr`. Avec un SNI joker, Traefik ne peut pas déduire pour quel nom demander le
   certificat : sans `domains`, il servirait son certificat par défaut et le navigateur refuserait.
4. Le service pointe sur l'adresse **interne** du conteneur, `ap-server-{sessionId}:38281`, pas sur
   un couple hôte/port publié.
5. Une session sans port n'émet aucun routeur (comportement actuel conservé).
6. Une configuration vide reste un objet JSON vide, jamais un tableau vide : Traefik refuserait la
   forme tableau. Le comportement actuel (`new \stdClass()`) est conservé pour les deux sections.

### Nettoyage

7. `$wsDomain` disparaît du builder, et `WS_DOMAIN` de `api/config/services.yaml:206`,
   `.env.prod.example:88`, `api/.env:77` et `api/.env.test:31`. Aucune occurrence ne subsiste dans
   le dépôt.
8. Le nom d'hôte `{sessionId}.ws.archilan.fr` n'est plus produit nulle part, et aucune entrée DNS
   n'est nécessaire pour qu'un run soit joignable.

### Tests et gates

9. `api/tests/Functional/TraefikAndPublisherTokenTest.php` est mis à jour : les cas existants
   (401 sans token, 401 avec mauvais token, config vide, session ignorée si non `running`, une
   session, plusieurs sessions) restent verts sur la **nouvelle forme** de la réponse.
10. Un test couvre explicitement la présence du certresolver et du domaine : c'est précisément
    l'oubli de 9.11, et c'est celui qui produit une panne invisible côté navigateur.
11. Un test couvre la dérivation entrypoint/port : deux sessions sur deux ports produisent deux
    routeurs sur deux entrypoints distincts.
12. `composer gates` passe.

## Tasks / Subtasks

- [ ] **Task 1** (AC 1-6). Réécrire `TraefikConfigBuilder::build()` : sortie `tcp.routers` /
  `tcp.services`, entrypoint dérivé du port, TLS avec certresolver et domaine, backend interne.
- [ ] **Task 2** (AC 7-8). Supprimer `$wsDomain` et `WS_DOMAIN` partout, y compris les fichiers
  d'environnement d'exemple et de test.
- [ ] **Task 3** (AC 9-11). Mettre à jour les tests fonctionnels, ajouter les cas certresolver et
  multi-ports.
- [ ] **Task 4** (AC 12). `composer gates`.

## Dev Notes

- **Routeur TCP plutôt que HTTP**, comme recommandé par l'epic : un tuyau, pas de parsing HTTP,
  moins de modes de défaillance sur une connexion qui dure des heures. Le prix assumé est la perte
  des logs d'accès par run - `accessLog` reste actif pour le reste du trafic.
- **La clé de routage est le port AP** (`Session::getPort()`), qui vaut `bridgePort + 10000` côté
  orchestrateur. Le builder n'a pas à connaître ce calcul : il lit le port stocké sur la session.
  En revanche la **convention de nommage de l'entrypoint** doit être identique des deux côtés, et
  rien dans le code ne le garantit - c'est un couplage par convention entre deux dépôts, à écrire
  dans le code et à vérifier au déploiement.
- **`Session::getHost()` reste utilisé ailleurs** (`SessionQuery.php:52`, affichage admin,
  diagnostic). Cette story ne le supprime pas ; elle cesse seulement de s'en servir **pour router**.
- **Ordre de déploiement.** Livrée seule, cette story remplace des routeurs inopérants par d'autres
  routeurs inopérants : sans les entrypoints de 37.1, Traefik journalise un entrypoint inconnu et
  ignore le routeur. Sans 37.3, le backend `ap-server-{id}:38281` n'est pas joignable depuis Traefik
  faute de réseau commun. **Les trois se déploient ensemble.**
- **Contrainte DDD.** Le builder est dans `Application/Support/`, il ne dépend que de
  `SessionRepositoryInterface` : garder cette pureté, pas de `Connection`, pas de conteneur
  Symfony au runtime (`api/CLAUDE.md`, AC-A2 et AC-A5).

### Project Structure Notes

- `api/src/Sessions/Application/Support/TraefikConfigBuilder.php` - le générateur.
- `api/config/services.yaml:204-206` - l'argument `$wsDomain` à retirer.
- `api/tests/Functional/TraefikAndPublisherTokenTest.php` - les tests à mettre à jour.
- Un test unitaire du builder est le bienvenu (il est pur, un `SessionRepositoryInterface` mocké
  suffit) mais les cas fonctionnels existants restent la référence.

### References

- [Source: _bmad-output/planning-artifacts/epics/epic-37-acces-wss-aux-serveurs-archipelago.md]
- [Source: api/src/Sessions/Application/Support/TraefikConfigBuilder.php:39-53] - routeur actuel
- [Source: orchestrateur/internal/service/session.go:498-499] - adresse interne `ap-server-{id}:38281`
- [Source: orchestrateur/internal/docker/client.go] - `CreateAPServer`, nom et port du conteneur
- [Source: _bmad-output/implementation-artifacts/37-1-entrypoints-traefik-et-provider-http.md] - convention de nommage des entrypoints

## Dev Agent Record

### Agent Model Used

### Completion Notes List

### File List

### Change Log

| Date | Change |
|------|--------|
| 2026-08-10 | Créée. Backend du routeur corrigé : adresse interne `ap-server-{id}:38281` au lieu du couple hôte/port publié, ce qui est la condition pour que 37.3 puisse refermer le port. |
