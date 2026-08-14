# Matrice de compatibilité des clients web Archipelago tiers

**Mesurée le 2026-08-14**, sur une run réelle derrière le reverse proxy de production
(`archilan.fr:35000`, certificat Let's Encrypt valide), depuis Chrome.

> **Ce résultat périme.** Aucun de ces clients ne nous appartient. Une version publiée sans préavis
> peut changer le parsing de l'adresse et invalider une ligne entière. Chaque verdict vaut pour un
> client nommé, à une date donnée. Rejouer la matrice avant toute communication joueur qui s'appuie
> dessus.

- Epic : [epic 37 - accès WSS aux serveurs Archipelago](../_bmad-output/planning-artifacts/epics/epic-37-acces-wss-aux-serveurs-archipelago.md)
- Story : [37.6](../_bmad-output/implementation-artifacts/37-6-matrice-compatibilite-clients-web-tiers.md)

## Verdict : GO

**Deux clients web tiers, publics et maintenus, se connectent à nos runs.** L'objectif de l'epic est
atteint : un joueur sans installation peut rejoindre une partie depuis son navigateur.

La question de fond a été vérifiée avant même les clients : depuis une page servie en HTTPS
(`https://archilan.fr`), un `new WebSocket('wss://archilan.fr:35000')` s'ouvre en **245 ms**. Le
certificat est accepté, la règle de contenu mixte ne s'applique pas, et l'échange protocolaire
complet passe :

```
RoomInfo        version 0.6.7, jeux ["Paint","Archipelago"], password true
Connect (slot invalide, mot de passe correct)
ConnectionRefused  errors: ["InvalidSlot"]
```

Le `InvalidSlot` seul - sans `InvalidPassword` - prouve que la requête a été comprise de bout en
bout par le serveur Archipelago à travers le proxy.

## Conclusion pour 37.4 et 37.5

**Il faut afficher les deux formes, et c'est mesuré, pas supposé.**

| Ce que l'UI doit donner | Pourquoi |
|---|---|
| **Hôte et port séparés** (`archilan.fr` et `35000`) | ap-tracker a deux champs distincts et **échoue** si on lui colle l'adresse jointe |
| **L'adresse jointe** (`archilan.fr:35000`) | Topher's Web Client n'a qu'un champ, et c'est la forme qui y fonctionne |
| L'URI complète (`wss://archilan.fr:35000`) | Utile au **client desktop** ; à ne pas présenter comme la forme web par défaut (voir le piège ci-dessous) |

**Le piège, observé** : la chaîne qui marche sur Topher's (`archilan.fr:35000`) **casse** ap-tracker
si on la colle dans son champ « Host » - `Connection failed`. Il n'existe donc pas une chaîne unique
à afficher, exactement comme l'epic le pressentait.

**L'aide à afficher quand un joueur se trompe** : les deux clients échouent en **une demi-seconde**,
sans timeout ni attente. Un échec instantané veut dire « adresse mal formée », pas « serveur
injoignable ».

## Topher's Archipelago Web Client

| | |
|---|---|
| URL | https://topheranselmo.com/archipelago/ |
| Formulaire | **un seul champ** URL, plus Name/Slot et Password |
| Bibliothèque | `archipelago.js` 2.0.4 |
| Testé le | 2026-08-14 |

| Forme | Chaîne collée | Verdict | Observation |
|---|---|---|---|
| A | `archilan.fr:35000` | **CONNECTÉ** | `masterkafey_P has joined! (Team 1)` |
| B | `wss://archilan.fr:35000` | **CONNECTÉ** | identique |
| C | `archilan.fr` | ÉCHEC en 0,5 s | voir le message ci-dessous |
| D | `wss://archilan.fr` | ÉCHEC en 0,5 s | message identique |

Message d'erreur affiché pour C et D, **verbatim** :

> Error: Failed to connect to server
> Tip: If you're trying to connect to a self-hosted archipelago server, you may need to access this
> client from an insecure http connection. Click here to access the insecure version.

**Ce conseil est faux dans notre cas, et il est nuisible.** Un joueur qui le suit passera sur une
version HTTP du client, qui ne pourra pas davantage joindre notre serveur - et il aura perdu le
chiffrement au passage. La vraie cause de C et D est l'absence de port : sans lui, `archipelago.js`
vise `38281`, où rien n'écoute. **37.5 doit dire explicitement que le port fait partie de
l'adresse**, faute de quoi le client enverra les joueurs dans une impasse.

**Connexion par paramètres d'URL** : vérifié, fonctionne.

```
https://topheranselmo.com/archipelago/#/?url=archilan.fr:35000&slot=NOM&password=MDP
```

Un lien de ce type connecte le joueur sans qu'il saisisse quoi que ce soit. **Le mot de passe y
figure en clair, dans une URL, vers un site tiers** - à trancher en 37.5, pas à faire par défaut.

## ap-tracker (DrAwesome4333)

| | |
|---|---|
| URL | https://drawesome4333.github.io/ap-tracker/ |
| Formulaire | **champs séparés** Host / Port / Slot / Password |
| Bibliothèque | `archipelago.js` vendorisée |
| Testé le | 2026-08-14 |

| Forme | Champ Host | Champ Port | Verdict | Observation |
|---|---|---|---|---|
| E | `archilan.fr` | `35000` | **CONNECTÉ** | inventaire et 0/130 locations affichés |
| F | `wss://archilan.fr` | `35000` | **CONNECTÉ** | le client concatène `host:port`, ce qui donne une URI valide |
| A dans Host | `archilan.fr:35000` | vide | **ÉCHEC** en 0,5 s | `An unexpected error occurred` puis `Connection failed` |

C'est un tracker, pas un client de discussion : il affiche l'inventaire et la progression. Il compte
néanmoins comme client web, puisqu'il se connecte à un serveur arbitraire et qu'un joueur peut s'en
servir sans rien installer.

## WebHost Archipelago (implémentation de référence)

**Résultat consigné : il n'y a pas de client web générique.** L'inventaire des gabarits de
`WebHostLib` ne contient aucun client navigateur visant un serveur arbitraire ; ses pages sont des
vues rendues côté serveur sur les rooms qu'il héberge lui-même.

L'implémentation de référence ne fournit donc **aucun** chemin web vers un serveur tiers. La
compatibilité repose entièrement sur des clients communautaires - ce qui rend cette matrice, et sa
péremption, structurelles.

## Candidats écartés

| Client | Critère non rempli | Détail |
|---|---|---|
| [ap-textclient](https://github.com/black-sliver/ap-textclient) | accessibilité publique | Client WASM navigateur, aucune instance hébergée documentée. Dernière activité 2025-05-03. |
| [APWebClient](https://github.com/MaxDistructo/APWebClient) | accessibilité publique | Dépôt actif, basé sur `archipelago.js`, mais ni GitHub Pages ni URL d'hébergement. |
| [ArchipelagoWebTextClient](https://github.com/kindasneaki/ArchipelagoWebTextClient) | maintenance | Dernière activité 2023-09-10, auto-hébergé. |

## Tenue sur connexion inactive

| Client | Forme | Inactivité | Résultat |
|---|---|---|---|
| Topher's Web Client | A | **16 minutes** | **Toujours vivante ET utilisable** |

La connexion a été établie puis laissée sans le moindre octet dans aucun sens pendant seize minutes,
onglet ouvert. Aucun message de déconnexion. Puis un `!players` envoyé sur cette même connexion a
reçu sa réponse du serveur :

```
masterkafey_P: !players
2 players of 1 connected :: Team #1: masterkafey_P
```

C'est le contrôle qui compte : une socket peut survivre côté client alors que le proxy a lâché en
silence. Ici la connexion répond encore, donc le chemin complet est intact.

Cette mesure recoupe celle faite en local le 2026-08-13 sur la chaîne complète, où une connexion TLS
totalement inactive a tenu **31 minutes** et restait utilisable. L'`idleTimeout` de 180 s de Traefik
ne s'applique pas aux routeurs TCP - le risque principal identifié par l'epic est écarté, désormais
en production et non plus seulement en laboratoire.

Ce qui reste non mesuré : une **partie réelle d'une heure ou plus**, avec ses pings et son trafic de
jeu. Elle se vérifiera d'elle-même à la première soirée.

## Journal

| Date | Événement |
|------|-----------|
| 2026-08-10 | Trame préparée : critères, candidats qualifiés sur pièces, formes d'adresse à tester. |
| 2026-08-14 | **Mesurée** sur une run réelle derrière le proxy de production. Verdict GO : deux clients tiers se connectent. Le piège des deux familles est confirmé en conditions réelles. |
