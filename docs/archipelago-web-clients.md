# Matrice de compatibilité des clients web Archipelago tiers

**Statut : brouillon - aucun résultat mesuré à ce jour.**
Ce document est la trame préparée le **2026-08-10** pour la story 37.6. Les candidats, les critères
et les formes d'adresse à tester sont arrêtés ; les colonnes de résultat sont vides et doivent être
remplies **par observation sur le banc de test**, pas par déduction.

> **Ce résultat périme.** Aucun de ces clients ne nous appartient. Une version publiée sans préavis
> peut changer le parsing de l'adresse et invalider une ligne entière de la matrice. Chaque verdict
> vaut pour un client nommé, à une date donnée. Rejouer la matrice avant toute communication joueur
> qui s'appuie dessus.

- Epic : [epic 37 - accès WSS aux serveurs Archipelago](../_bmad-output/planning-artifacts/epics/epic-37-acces-wss-aux-serveurs-archipelago.md)
- Story : [37.6](../_bmad-output/implementation-artifacts/37-6-matrice-compatibilite-clients-web-tiers.md)
- Runbook du banc : section « Runbook du banc de test » de la story 37.6.

## Pourquoi cette matrice existe

Une page servie en HTTPS ne peut pas ouvrir un WebSocket en `ws://`. Les clients web Archipelago
tiers ne peuvent donc joindre nos runs que si le serveur est exposé en `wss://` derrière un
certificat réellement valide. L'architecture retenue expose chaque run sur
`wss://archilan.fr:{port}`, avec un port de la plage `25000-25099`.

Ce que la matrice mesure : **comment chaque client interprète l'adresse qu'on lui donne**, et donc
quelle chaîne exacte l'UI (37.5) doit afficher aux joueurs.

## Critères d'inclusion d'un candidat

Un client entre dans la matrice s'il coche les quatre :

1. **Web** : s'exécute dans un navigateur, sur une page servie en HTTPS.
2. **Publiquement accessible** : une instance hébergée existe, utilisable sans build ni
   auto-hébergement par le joueur.
3. **Serveur arbitraire** : l'utilisateur peut viser un hôte **et** un port de son choix.
4. **Maintenu** : activité du dépôt dans les douze derniers mois.

Un client qui échoue au critère 2 ou 4 est consigné dans « Candidats écartés » avec la raison, pas
supprimé de la liste : c'est la trace de la recherche menée (AC 8).

## La variable mesurée : quatre formes d'adresse

À tester **systématiquement sur chaque client**, y compris celles qu'on s'attend à voir échouer. Le
mode d'échec est une donnée de sortie, pas un détail : 37.5 devra aider un joueur qui se trompe de
forme, donc il faut savoir à quoi ressemble l'erreur de son côté.

| Forme | Chaîne exacte à coller |
|---|---|
| A - hôte + port, nu | `archilan.fr:25099` |
| B - URI complète | `wss://archilan.fr:25099` |
| C - hôte seul, nu | `archilan.fr` |
| D - URI sans port | `wss://archilan.fr` |

Pour les clients à **champs séparés** (hôte d'un côté, port de l'autre), tester en plus :

| Forme | Champ hôte | Champ port |
|---|---|---|
| E - champs séparés, hôte nu | `archilan.fr` | `25099` |
| F - champs séparés, hôte préfixé | `wss://archilan.fr` | `25099` |

Remplacer `25099` par le port réellement ouvert sur le banc.

## Candidats retenus

### 1. Topher's Archipelago Web Client

| | |
|---|---|
| URL | https://topheranselmo.com/archipelago/ |
| Source | https://github.com/christopherwk210/tophers-archipelago-web-client |
| Bibliothèque | `archipelago.js` 2.0.4 (dépendance déclarée) |
| Dernière activité constatée | 2026-06-07 |
| Forme du formulaire | **un seul champ** `url`, passé tel quel à `client.login()` |
| Version affichée dans l'UI | à relever le jour du test |

Résultats :

| Forme | Chaîne collée | Verdict | Mode d'échec observé |
|---|---|---|---|
| A | `archilan.fr:25099` | à mesurer | |
| B | `wss://archilan.fr:25099` | à mesurer | |
| C | `archilan.fr` | à mesurer | |
| D | `wss://archilan.fr` | à mesurer | |

À vérifier aussi sur ce client : il accepte des **paramètres d'URL** pour préremplir le formulaire,
documentés dans sa source sous la forme
`https://topheranselmo.com/archipelago/#/?url=archipelago.gg:12345&slot=my_name` (`&password=` est
également lu). Si cela fonctionne avec notre adresse, 37.5 peut envisager un lien de connexion
direct - avec la réserve que **le mot de passe passerait alors dans une URL vers un tiers**, ce qui
doit être décidé explicitement et pas subi.

### 2. ap-tracker (DrAwesome4333)

| | |
|---|---|
| URL | https://drawesome4333.github.io/ap-tracker/ |
| Source | https://github.com/DrAwesome4333/ap-tracker |
| Bibliothèque | `archipelago.js` (copie vendorisée dans `external/`, version à relever) |
| Dernière activité constatée | 2026-08-09 |
| Forme du formulaire | **champs séparés** hôte / port / slot / mot de passe ; le client concatène `${host}:${port}` lui-même |
| Version affichée dans l'UI | à relever le jour du test |

C'est un tracker, pas un client texte, mais il se connecte à un serveur arbitraire : il compte au
titre du critère 3 et il représente la **famille « champs séparés »**, qui est précisément celle qui
casse si on affiche une URI complète.

Résultats :

| Forme | Champ hôte | Champ port | Verdict | Mode d'échec observé |
|---|---|---|---|---|
| E | `archilan.fr` | `25099` | à mesurer | |
| F | `wss://archilan.fr` | `25099` | à mesurer | |
| A (dans le champ hôte) | `archilan.fr:25099` | vide | à mesurer | |
| C (port laissé vide) | `archilan.fr` | vide | à mesurer | |

### 3. WebHost Archipelago (implémentation de référence)

| | |
|---|---|
| URL | https://archipelago.gg |
| Source | https://github.com/ArchipelagoMW/Archipelago (répertoire `WebHostLib`) |

**Constat préparatoire, à confirmer le jour du test :** l'inventaire des gabarits de `WebHostLib`
ne contient aucun client texte navigateur visant un serveur arbitraire. Les pages web du WebHost
(`hostRoom`, `tracker__*`, `multitracker*`, `genericTracker`) sont des vues rendues côté serveur sur
les rooms que le WebHost héberge lui-même.

Si ce constat tient, **c'est le résultat** : l'implémentation de référence ne fournit pas de chemin
web vers un serveur tiers, et la compatibilité repose entièrement sur des clients communautaires.
C'est une donnée à consigner, pas un échec du test (AC 7).

## Candidats écartés

| Client | Source | Critère non rempli | Détail |
|---|---|---|---|
| ap-textclient (black-sliver) | https://github.com/black-sliver/ap-textclient | 2 - accessibilité publique | Client WASM navigateur, mais aucune instance hébergée documentée : le README ne décrit que le build local servi par `serve.py` sur `http://localhost:8000`. Dernière activité 2025-05-03. |
| APWebClient (MaxDistructo) | https://github.com/MaxDistructo/APWebClient | 2 - accessibilité publique | Dépôt actif (2026-08-04), basé sur `archipelago.js` ^2.0.4, mais ni page GitHub Pages ni URL d'hébergement déclarée. |
| ArchipelagoWebTextClient (kindasneaki) | https://github.com/kindasneaki/ArchipelagoWebTextClient | 4 - maintenance | Dernière activité 2023-09-10. Auto-hébergé de surcroît. |

Recherche menée le 2026-08-10 : wiki Archipelago (page « Client »), recherche web sur les clients
web et navigateur, dépôts liés à `archipelago.js`. Si un candidat hébergé apparaît d'ici le test, il
s'ajoute à la matrice.

## Tenue sur connexion inactive longue

Alimente le risque d'idle timeout que 37.1 doit trancher (`respondingTimeouts.idleTimeout` vaut
180 s par défaut chez Traefik, une partie dure des heures).

| Client | Heure d'ouverture | Durée d'inactivité | Connexion encore vivante ? | Observations |
|---|---|---|---|---|
| à compléter | | | | |

Cible : **15 minutes minimum** sans aucune activité côté joueur, connexion laissée ouverte,
onglet au premier plan. Noter si le client se reconnecte tout seul (ce qui masquerait une coupure).

## Verdict

**Go / no-go : à écrire après mesure.**

Critère : au moins un client web tiers, publiquement accessible et maintenu, se connecte à
`wss://archilan.fr:{port}` avec une chaîne d'adresse qu'un joueur peut raisonnablement recopier.

Rappel de cadrage : même un no-go côté clients tiers ne rend pas la chaîne d'infra 37.1-37.3
inutile. Elle livre le `wss` pour le client desktop (qui le gère, vérifié le 2026-08-08) et referme
l'exposition en clair du port Archipelago de chaque run.

## Conclusion pour 37.4 et 37.5

**À écrire après mesure.** Doit répondre noir sur blanc à :

1. Combien de formes d'adresse l'UI doit-elle afficher : une, deux, ou une par client ?
2. Quelle chaîne **verbatim** pour chacune ?
3. Le couple hôte / port séparé doit-il être exposé au joueur en plus de l'URI, à cause de la
   famille « champs séparés » ?
4. Quel message d'aide afficher pour le mode d'échec le plus probable, tel qu'observé ?

## Journal

| Date | Événement |
|------|-----------|
| 2026-08-10 | Trame préparée : critères d'inclusion, candidats qualifiés sur pièces, formes d'adresse à tester, runbook du banc. Aucune mesure effectuée. |
