# Exposer les serveurs Archipelago derrière le reverse proxy

Epic 37. Chaque run Archipelago doit être joignable en `wss://` pour qu'un client web puisse s'y
connecter : une page servie en HTTPS ne peut pas ouvrir un WebSocket en clair.

**Le reverse proxy n'est pas versionné dans ce dépôt.** Il sert plusieurs projets de l'hôte, il est
configuré en arguments de ligne de commande, et il vit dans son propre `docker-compose.yml` hors du
dépôt. Ce document dit quoi y ajouter ; il ne le fait pas à ta place.

> Un répertoire `traefik/` a existé ici jusqu'à la v0.14.0. Il décrivait une configuration qui n'a
> **jamais été déployée** - d'où le `${ACME_EMAIL}` qu'il contenait, jamais interpolé, et que
> personne n'avait remarqué. Il a été supprimé pour que le dépôt cesse de décrire une
> infrastructure imaginaire.

## Prérequis : Traefik v3.6.1 minimum

**Pas n'importe quelle v3 : v3.6.1 ou plus récente.** Constaté en production le 2026-08-14, sur
une v3.3 : le provider Docker ne parvient plus à parler au démon.

```
ERR Failed to retrieve information of the docker client and server host
    error="Error response from daemon: client version 1.24 is too old.
    Minimum supported API version is 1.40" providerName=docker
```

Traefik jusqu'à la 3.5 épingle l'API Docker à la version 1.24 ; Docker 29 a relevé le minimum. La
négociation automatique de version est arrivée **en 3.6.1**. La v2.11, elle, négociait - d'où
l'illusion que la migration ne changeait rien sur ce point.

**Le symptôme est brutal et trompeur** : Traefik démarre, sert le TLS, et répond **404 à tout**.
Sans provider Docker, il ne lit aucun libellé, donc ne connaît aucun service - les 27 du serveur,
pas seulement ceux d'archilan. On croit à un problème de règles ou de certificats ; c'est le
provider.

Épingler une version exacte (`traefik:v3.6.25`), pas un tag flottant : `docker compose up -d` ne
retire pas une image déjà en cache, et `restart` ne change même pas d'image.

## Migration v2 vers v3

**L'option `headers` du provider HTTP n'existe qu'à partir de la v3.** En v2.11, Traefik **refuse
de démarrer** - vérifié le 2026-08-14 :

```
command traefik error: failed to decode configuration from flags: field not found, node: headers
```

Le proxy porte **tout** le trafic entrant de l'hôte. Coller le fragment sans avoir migré ne rend pas
les runs injoignables : ça met l'hôte entier à terre, les autres projets compris. Il n'y a pas de
contournement propre en v2 - c'est le prérequis de toute la chaîne, et il se vérifie avant de coller
quoi que ce soit.

Pour une configuration du type de la nôtre :

- `--providers.docker`, `--entrypoints.*`, les redirections `web` → `websecure`, et
  `--certificatesresolvers.<nom>.acme.tlschallenge` passent **inchangés** ;
- le provider Docker perd Swarm (provider dédié en v3) - sans objet ici ;
- la **syntaxe des règles de routeurs** change (`PathPrefix` n'interprète plus les expressions
  régulières, `Headers` devient `Header`, `IPWhiteList` devient `IPAllowList`). Les règles en
  `Host(...)` et les opérateurs `||` restent valides.

**Migrer la v3 SEULE d'abord**, sans aucun flag de l'epic 37, et vérifier que les services
répondent. Le 2026-08-14 les deux ont été faits d'un coup : le proxy s'est retrouvé avec un provider
Docker muet **et** un token absent, soit deux pannes superposées sur un hôte qui porte 27 services.
Séparées, chacune se diagnostique en une minute.

**Filet de sécurité pour les autres projets de l'hôte** : ajouter
`--core.defaultRuleSyntax=v2` au moment de la bascule. Les règles écrites pour la v2 continuent
d'être comprises, et on migre les libellés plus tard, projet par projet. Sans ça, un routeur d'un
autre site peut cesser de correspondre en silence.

### Avant de toucher au proxy : inventorier les libellés des autres projets

```bash
./scripts/traefik-v3-preflight.sh      # sur l'hôte du proxy
```

Il lit les libellés Traefik de **tous** les conteneurs de l'hôte et signale les formes que la v3
ne comprend plus. Il ne modifie rien, et il sort en erreur s'il trouve quelque chose.

Ses deux angles morts, à ne pas confondre avec un feu vert : les conteneurs **arrêtés** ne sont pas
inspectés, et la configuration dynamique par fichier du proxy n'est pas lue.

### Retour arrière du passage en v3

À écrire avant, pas pendant. Le proxy est le point d'entrée unique de l'hôte : s'il ne repart pas,
tout est down, y compris les autres projets.

1. **Épingler l'image v2 avant de basculer** - noter le digest exact, pas seulement `traefik:v2.11` :
   `docker inspect traefik --format '{{.Image}}'`.
2. Pour revenir : remettre `image: traefik:v2.11` (ou le digest noté), **retirer le fragment**
   collé (entrypoints `ap-*`, bloc `providers.http`, plage de ports), `docker compose up -d`.
3. Le retour arrière du proxy **n'annule pas** le basculement de l'orchestrateur. Si les runs
   doivent redevenir joignables, remettre aussi `AP_PUBLISH_HOST_PORT=true` et redémarrer
   l'orchestrateur - sinon les serveurs Archipelago n'écoutent plus nulle part de public.
4. Vérifier dans cet ordre : le site répond, l'API répond, une run se lance et publie son port.

L'`acme.json` n'est pas migré par la bascule : la v3 lit le magasin de la v2. Le sauvegarder quand
même avant (`cp letsencrypt/https-acme.json{,.avant-v3}`) coûte une seconde et évite de tout
redemander à Let's Encrypt en cas de fausse manœuvre.

## Ce qu'il faut ajouter au proxy

```bash
./scripts/gen-traefik-entrypoints.sh --env-file .env.prod
```

Le script imprime le fragment à coller : un entrypoint `ap-{port}` par port de la plage, le bloc
`providers.http`, et la ligne de publication des ports. Il ne modifie aucun fichier.

La plage est dérivée de `PORT_RANGE_START`, `PORT_RANGE_END` et `AP_SERVER_PORT_OFFSET` - les mêmes
variables que celles de l'orchestrateur. Avec les valeurs de production : **35000-35099**.
`PORT_RANGE_*` alloue le port du *bridge* ; le serveur Archipelago écoute sur `port du pool +
offset`. **Ne jamais écrire ces entrypoints à la main** : deux sources de vérité pour une plage de
ports, c'est une désynchronisation silencieuse le jour où quelqu'un élargit le pool.

Il faut aussi :

- que le conteneur du proxy **partage un réseau** avec les conteneurs de session, sinon il ne peut
  pas joindre `ap-server-{sessionId}:38281`. C'est la valeur de `PROXY_NETWORK` côté orchestrateur.
  Vérifié sur l'hôte le 2026-08-13 : le proxy n'est que sur le réseau **`traefik`**, et sur aucun
  autre. C'est donc celui que les conteneurs de session doivent rejoindre ;
- que `TRAEFIK_CERT_RESOLVER` côté API porte le nom du certresolver **tel qu'il est déclaré dans le
  proxy**. Un nom inconnu ne provoque aucune erreur visible : Traefik sert son certificat par
  défaut et le navigateur refuse la connexion, sans interstitiel.

## Ordre de bascule - non négociable

Le proxy publie **toute** la plage sur l'hôte dès son démarrage. Tant que l'orchestrateur tourne
avec `AP_PUBLISH_HOST_PORT=true`, chaque run publie *aussi* son port dans cette plage. Deux
liaisons sur le même port ne peuvent pas coexister :

- une run active sur un port de la plage au démarrage du proxy → **le proxy ne démarre pas**, et il
  porte tout le trafic entrant de l'hôte ;
- le proxy démarré d'abord → **plus aucune run ne peut se lancer** (`port is already allocated`).

Dans une seule fenêtre calme, sans run active :

1. `AP_PUBLISH_HOST_PORT=false` + `PROXY_NETWORK=<réseau du proxy>` dans `envs/orchestrateur.env`,
   puis redémarrage de l'orchestrateur.
2. `ss -lntp | grep -c ':35[0-9][0-9][0-9]'` doit renvoyer `0`. Les runs déjà lancées gardent leur
   liaison jusqu'à leur arrêt.
3. Coller le fragment, passer l'image en v3, redémarrer le proxy.
4. `docker inspect traefik --format '{{json .NetworkSettings.Ports}}'` - les liaisons doivent être
   **non vides** (voir le piège ci-dessous).
5. Lancer une run et vérifier.

Entre 1 et 3, une run lancée n'est joignable par personne. D'où la fenêtre unique.

### Le piège du redémarrage sur un port pris

Message exact : `Bind for 0.0.0.0:35042 failed: port is already allocated`. Le conteneur est **créé
mais pas démarré**.

**Ne pas enchaîner sur `docker start traefik`.** Vérifié le 2026-08-13 : après cet échec, un
`docker start` **réussit** et lance le proxy **sans aucune liaison de port** - `docker ps` affiche
`Up`, et `docker inspect` montre des liaisons vides :

```
{"35040/tcp":[],"35041/tcp":[],"35042/tcp":[]}
```

Le proxy a l'air sain et ne publie rien, **80 et 443 compris** : tout l'hôte est down pendant qu'on
croit le problème réglé. La bonne réaction est de supprimer le conteneur, libérer le port en
conflit, puis recréer.

## Diagnostiquer un run injoignable

| Symptôme sur le port d'un run | Interprétation |
|---|---|
| Poignée TLS réussie, puis **HTTP 404** | Aucun routeur ne correspond à cet entrypoint. |
| Poignée TLS réussie, réponse du serveur Archipelago | Tout va bien. |
| Connexion refusée | L'entrypoint n'existe pas, ou le port n'est pas publié. |
| Certificat invalide / `TRAEFIK DEFAULT CERT` | Le nom du certresolver ne correspond pas à celui du proxy, ou le certificat n'existe pas pour ce nom d'hôte. |

Le premier cas est le piège : `curl` renvoie un code de sortie 0, la connexion « marche », et seul
le contenu trahit le problème. Pour un client WebSocket, cela se présente comme un échec de
négociation sans explication.

```bash
curl -sk -o /dev/null -w "%{http_code}\n" https://{hôte}:35042/   # 404 = aucun routeur
openssl s_client -connect {hôte}:35042 -servername {hôte} </dev/null 2>/dev/null \
  | openssl x509 -noout -issuer -subject
docker logs traefik | grep -i "entryPoint"                        # « EntryPoint doesn't exist »
```

Cause la plus probable d'un 404 : la plage des entrypoints et le port du run ne concordent plus -
typiquement `PORT_RANGE_*` ou `AP_SERVER_PORT_OFFSET` modifié d'un côté sans régénérer de l'autre.

## Ce qui a été mesuré, et ce qui ne l'a pas été

Validé en local le 2026-08-13, sur la chaîne complète (configuration générée, provider HTTP servant
la réponse de l'API, routeur TCP, backend joint par nom de conteneur) :

- le routage fonctionne de bout en bout, et le nom d'entrypoint produit par le script correspond à
  celui que construit l'API ;
- une connexion TLS **totalement inactive pendant 31 minutes** reste ouverte **et utilisable**.
  L'`idleTimeout` de 180 s ne s'applique pas aux routeurs TCP : c'était le risque principal de
  l'architecture, il tombe.

Non mesuré, et qui ne peut l'être qu'en production : l'obtention réelle du certificat, le coût de
cent entrypoints au démarrage, et la tenue d'une vraie partie d'au moins une heure.
