# Epic 37: Accès WSS aux serveurs Archipelago

**Statut :** draft
**Date :** 2026-08-08
**Origine :** besoin remonté par Jean le 2026-08-08 - les clients web Archipelago tiers ne peuvent pas
se connecter à nos runs.

## Objectif

Exposer chaque serveur Archipelago derrière une connexion **TLS valide**, pour que les clients web
Archipelago hébergés par des tiers puissent s'y connecter depuis une page HTTPS.

> « Le déclencheur c'est le client web dans le navigateur. » - Jean, 2026-08-08

## Le déclencheur

Une page servie en HTTPS ne peut pas ouvrir un WebSocket en `ws://` : la règle de contenu mixte du
navigateur l'interdit, sans exception exploitable et **sans interstitiel**. La connexion échoue
silencieusement, l'utilisateur voit seulement quelque chose qui ne marche pas. Aujourd'hui nos runs ne
sont joignables qu'en clair sur `{host}:{port}`, donc aucun client web tiers ne peut les atteindre.

Corollaire souvent sous-estimé : le certificat doit être **réellement valide et reconnu**. Là où un
client desktop tolérerait un certificat auto-signé, le navigateur refuse net, et l'utilisateur n'a
aucun moyen de passer outre pour une connexion WebSocket.

## Constat de départ (vérifié dans le code, 2026-08-08)

**Le serveur Archipelago parle nativement WebSocket.** `MultiServer` tourne sur la lib `websockets` :
le port 38281 est déjà un endpoint `ws://`, pas du TCP brut. Il n'y a donc **aucune traduction de
protocole à écrire** - uniquement de la terminaison TLS devant un endpoint qui existe déjà.

**La story 9.11 (done) a construit une moitié du chemin, jamais branchée.**
`TraefikConfigBuilder` génère déjà un routeur par run vers `{runId}.ws.archilan.fr` sur l'entrypoint
`websecure` (`api/src/Sessions/Application/Support/TraefikConfigBuilder.php:39-46`), et l'endpoint
`GET /api/v1/internal/traefik` est exposé et authentifié. Mais :

- `traefik/traefik.yml:16-22` ne déclare que les providers `docker` et `file`. **Aucun bloc
  `providers.http`** : la config produite par 9.11 n'est consommée par personne.
- Les routeurs émettent `'tls' => new \stdClass()` (`TraefikConfigBuilder.php:45`), sans certresolver.
  Traefik servirait son certificat auto-signé - rejet immédiat côté navigateur.
- Aucun enregistrement DNS `*.ws.archilan.fr` n'existe.

**Le port du conteneur est publié en clair sur l'hôte.** `runner/app/docker_manager.py:104` mappe
`{container_port}/tcp -> host_port` sur `0.0.0.0`. Chaque run ouvre donc un port public en clair, et
c'est ce port que l'API surface aujourd'hui comme adresse de connexion.

**Rien ne surface d'adresse chiffrée aux joueurs.** L'UI affiche hôte et port bruts
(`frontend/src/features/personal-runs/connection-details.tsx:29-30`), et le contrat
`connectionInfo: { host, port, password }` traverse Sessions, PersonalRuns, WeeklyRuns et les mails
d'événement.

**Le pool de ports est borné et connu :** `PORT_RANGE_START=25000` / `PORT_RANGE_END=25099`
(`.env.prod.example:50-51`), soit cent runs simultanés au maximum.

## Décisions d'architecture (Jean, 2026-08-08)

| Question | Décision |
|---|---|
| Clé de routage | **Le port**, un par run - pas le sous-domaine |
| Certificat | Le certificat `archilan.fr` **existant**, partagé par tous les runs |
| Schéma servi | **TLS uniquement** sur le port du pool |
| Client web | **Tiers uniquement**, on n'en héberge pas |

### Pourquoi le port plutôt que le sous-domaine

Le port ne fait pas partie de la validation d'un certificat : un client qui ouvre
`wss://archilan.fr:25042` valide le nom `archilan.fr` contre le SAN et **ignore le port**. Le
certificat déjà émis par le resolver letsencrypt (`traefik/traefik.yml:24-32`) couvre donc tous les
runs, sur n'importe quel port du pool.

Ça supprime d'un coup le wildcard `*.ws.archilan.fr`, le DNS-01 dédié, l'enregistrement DNS par run et
la variable `WS_DOMAIN`. C'est une simplification majeure par rapport au design de la story 9.11.

Le corollaire est que **le port identifie le run à lui seul**, donc plus aucun démultiplexage par SNI
n'est nécessaire : il n'y a qu'un backend derrière chaque port.

### Pourquoi TLS uniquement, sans détection du trafic en clair

L'option envisagée était de sniffer le premier octet sur le port (`0x16` = ClientHello TLS, `G` =
`GET` en clair) pour servir les deux schémas sur la même socket. Test effectué par Jean le
2026-08-08 : **le client Archipelago desktop se connecte en `wss://` sans difficulté**. Le chemin en
clair n'a donc plus d'utilisateur, et le sniffing disparaît du périmètre avec lui.

Une seule adresse, `wss://archilan.fr:{port}`, sert desktop et web.

### Pourquoi Traefik plutôt qu'un sidecar TLS par session

Un terminateur TLS par conteneur de session imposerait de distribuer la **clé privée de production**
dans des conteneurs éphémères et d'y gérer le renouvellement. Avec Traefik la clé reste dans un seul
`acme.json`, sous un seul processus. C'est l'argument décisif, et il l'emporte sur la lourdeur des
entrypoints statiques décrite ci-dessous.

## Ce que l'epic ferme et ce qu'il ouvre

**Ferme :** l'exposition publique en clair du port Archipelago de chaque run. Traefik devient
détenteur de la seule socket publique du run.

**Ouvre :** la plage `25000-25099` doit être déclarée en **entrypoints statiques** dans `traefik.yml`.
Le plafond de runs simultanés, aujourd'hui souple côté orchestrateur, devient rigide : l'élargir
imposera un redémarrage de Traefik.

**N'adresse pas :** le port du bridge, lui aussi publié sur l'hôte
(`runner/app/docker_manager.py:105-106`), parce que l'API le joint par port hôte
(`docker-compose.prod.yml:97`). C'est une seconde exposition, réelle, mais qui demande de revoir le
chemin API vers bridge. Elle est explicitement hors périmètre ici - l'epic ne doit pas laisser croire
qu'il referme toute la surface publique d'un run.

**Renonce à :** la traversée de pare-feu par le 443. Un port haut sera bloqué là où le 443 passait.
C'est cohérent avec le déclencheur (le navigateur, pas le réseau), mais l'option ne sera pas
récupérable sans revenir au routage par sous-domaine.

## Découpage en stories

| # | Story | Contenu |
|---|---|---|
| 37.1 | Entrypoints Traefik et provider HTTP | Génération des entrypoints depuis `PORT_RANGE_START`/`PORT_RANGE_END` (jamais à la main) + bloc `providers.http` vers `/api/v1/internal/traefik`. Une seule source de vérité pour la plage. |
| 37.2 | `TraefikConfigBuilder` - routage par port | Un routeur par run sur l'entrypoint de son port, TLS sur le certificat `archilan.fr` partagé. Suppression de `$wsDomain` et de `WS_DOMAIN` (`services.yaml:206`, `.env.prod.example:88`, `api/.env:77`). |
| 37.3 | Runner - fin de l'exposition publique du port AP | `docker_manager.py:104` cesse de publier le port Archipelago sur `0.0.0.0`. Deux options à trancher dans la story : attacher les conteneurs de session au réseau `archilan-proxy`, ou binder sur `127.0.0.1`. |
| 37.4 | Contrat API - l'URI wss dans `connectionInfo` | L'adresse `wss://` propagée dans Sessions, PersonalRuns et WeeklyRuns. Le couple hôte/port brut est conservé pour l'admin et le diagnostic, pas comme adresse joueur. |
| 37.5 | Surfacage UI et mails | `connection-details.tsx`, boutons copier, pages run perso et run hebdo, mails d'événement. Mention explicite de ce qu'un client tiers reçoit quand on l'y envoie. |
| 37.6 | Matrice de compatibilité des clients web tiers | Clients nommés, versions testées, forme d'adresse à coller pour chacun. Accrochée aux tutoriels de l'epic 31. |

Ordre conseillé : **37.6 en premier**, avant toute écriture de code. C'est elle qui détermine ce que
37.4 et 37.5 doivent afficher, et si aucun client tiers exploitable ne supporte proprement le `wss` sur
un port du pool, l'epic entier tombe. Ensuite 37.1 -> 37.3 (la chaîne technique, qui n'a de sens que
complète), puis 37.4 et 37.5.

## Risques et points de vigilance

- **Le format d'adresse n'est pas sous notre contrôle.** Les clients tiers se répartissent en deux
  familles incompatibles : ceux qui attendent une adresse nue et préfixent `wss://` eux-mêmes (leur
  donner l'URI complète produit `wss://wss://...`), et ceux qui attendent une URI complète (leur
  donner l'adresse nue les fait retomber en `ws://` ou ajouter `:38281`). **Il n'existe pas une chaîne
  unique qui marche partout** - c'est tout l'objet de 37.6, et la raison pour laquelle 37.5 ne peut pas
  se contenter d'un champ « adresse ».

- **Dépendance à des tiers qui peuvent casser sans préavis.** « Ça marche » n'est pas une propriété de
  notre code, c'est un fait observé sur des clients nommés à une version donnée. Sans la matrice de
  37.6, le support devient ingérable. Le client desktop reste le chemin de repli.

- **Idle timeout du proxy. Non testé à ce jour.** `respondingTimeouts.idleTimeout` vaut 180 s par
  défaut chez Traefik, alors qu'une partie Archipelago dure des heures. La lib `websockets` d'AP envoie
  des pings, donc ça devrait tenir, mais c'est exactement le genre de défaut qui passe les tests et
  casse en production au bout de trois minutes. **37.1 doit le valider explicitement sur une connexion
  longue réelle, pas le supposer.**

- **Routeur TCP ou HTTP** sur l'entrypoint dédié - à trancher en 37.2. Recommandation : TCP
  (`HostSNI(\`*\`)` + tls), tuyau bête, pas de parsing HTTP, moins de modes de défaillance. Le prix est
  la perte des logs d'accès par run.

- **Aucune protection d'origine.** Les WebSockets échappent au CORS : pas de préflight, aucun contrôle
  d'`Origin` côté serveur AP. Une fois le run exposé, n'importe quelle page web peut ouvrir une
  connexion. Le mot de passe Archipelago reste la **seule** barrière réelle.

- **Le port n'apporte aucune obscurité.** Une plage bornée sur un hôte connu se scanne en quelques
  secondes. Contrairement au sous-domaine en UUID du design 9.11, il n'y a ici aucune capability
  implicite. À assumer, pas à croire acquis.

- **On envoie les joueurs coller leurs identifiants chez un tiers.** Adresse, nom de slot et mot de
  passe transitent par un site qu'on ne contrôle pas. Ce n'est pas un sujet RGPD au sens strict (c'est
  l'action volontaire du joueur), mais recommander un tiers sans dire ce qu'il reçoit serait incohérent
  avec la posture affichée par l'association. Une phrase dans l'UI suffit, mais elle doit être décidée
  en 37.5, pas oubliée.

- **Deux sources de vérité pour la plage de ports.** `PORT_RANGE_*` côté orchestrateur et les
  entrypoints côté Traefik doivent rester synchronisés. 37.1 doit générer les seconds depuis les
  premières, sinon une désynchronisation silencieuse attend le jour où quelqu'un élargit le pool.

- **Ne pas reconstruire 9.11.** L'endpoint provider, son authentification par `X-Traefik-Token` et ses
  tests fonctionnels existent et restent valides. Cet epic rebranche et resimplifie ce qui est là ; il
  ne repart pas de zéro.

## Change Log

| Date | Description |
|------|-------------|
| 2026-08-08 | Créé (draft). Déclencheur : clients web tiers bloqués par la règle de contenu mixte. Architecture arbitrée avec Jean : routage par port sur le certificat `archilan.fr` partagé (abandon du wildcard et du sous-domaine par run du design 9.11), TLS uniquement après confirmation que le client desktop gère `wss://`. Découpage en 6 stories. |
