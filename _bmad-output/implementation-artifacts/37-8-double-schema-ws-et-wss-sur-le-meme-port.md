# Story 37.8: Double schéma - servir `ws` et `wss` sur le même port

**Status:** ready-for-dev - **AC 1 levé le 2026-08-15** sur banc local (Traefik v3.6.25)
**Epic:** 37 - Accès WSS aux serveurs Archipelago
**Date:** 2026-08-15
**Dépend de :** 37.1, 37.2, 37.3 (livrées). Ne modifie ni 37.4 ni 37.5.

## Story

En tant que joueur utilisant un mod de jeu qui fait office de client Archipelago,
je veux pouvoir rejoindre une run avec un client qui ne sait parler qu'en `ws://`,
afin de retrouver l'accès que l'epic 37 m'a retiré sans l'avoir vu.

## Context

### C'est une régression de production, pas une évolution

Avant l'epic, le conteneur du serveur Archipelago publiait son port en clair sur l'hôte, et un
client ws-only s'y connectait. La story 37.3 a fermé cette liaison
(`AP_PUBLISH_HOST_PORT=false`, vérifié en production le 2026-08-14). Depuis cette date, **tout
client incapable de faire du TLS est exclu de nos runs**, sans message compréhensible : Traefik ne
trouve aucun routeur TCP non-TLS sur l'entrypoint, repasse la connexion à son handler HTTP par
défaut, et le client reçoit un **HTTP 404** en réponse à sa requête d'upgrade.

Personne ne l'a détecté parce que la validation de l'epic a porté sur les deux familles qui vont
bien : le client desktop (testé le 2026-08-08) et les clients web tiers (matrice 37.6, mesurée le
2026-08-14). Les mods de jeu n'ont été testés par aucune des deux.

### La décision qui n'a jamais été prise

L'epic portait une section `Pourquoi TLS uniquement, sans détection du trafic en clair`, qui
concluait que le chemin en clair n'avait plus d'utilisateur et écartait le double schéma du
périmètre. **Cette décision n'a jamais été arbitrée : elle a été ajoutée à la rédaction de l'epic.**
Confirmé par Jean le 2026-08-15 - l'intention a toujours été de servir `ws` et `wss` en même temps
sur le même port.

Ce qui est exact dans cette section, c'est le constat du 2026-08-08 : le client Archipelago desktop
se connecte en `wss://` sans difficulté. Ce qui a été inventé, c'est le saut de ce constat à
« donc plus personne n'a besoin du clair ». Le `wss` était le déclencheur de l'epic, pas un
remplacement du `ws`.

La famille que cette fiction a exclue est celle des **mods de jeu embarquant leur propre client
Archipelago**, dont une partie n'implémente que `ws://`.

**L'epic est déjà corrigé** : section barrée et remplacée, ligne « Schéma servi » du tableau de
décisions amendée, entrée de change log du 2026-08-15. Cette story n'a donc rien à y re-litiger,
elle met le code en conformité avec l'intention réelle.

### Ce qui rend la correction quasi gratuite

Le sniffing que l'epic envisageait d'écrire, **Traefik le fait déjà nativement**. Il inspecte les
premiers octets d'une connexion TCP : ClientHello reconnu, la connexion part au muxer TLS ; sinon,
elle est confrontée aux routeurs TCP non-TLS du même entrypoint. Il suffit donc de déclarer un
second routeur sans bloc `tls`, vers le même service.

Trois conséquences qui définissent le périmètre :

- **Même port.** `ws://{hôte}:35000` et `wss://{hôte}:35000` cohabitent sur `ap-35000`. Aucune
  nouvelle plage, aucune règle de pare-feu, aucun changement dans l'orchestrateur.
- **Aucun changement de contrat ni d'affichage.** L'adresse `{hôte}:{port}` déjà surfacée par 37.4
  et affichée par 37.5 devient utilisable telle quelle par les mods. Rien à ajouter dans
  `connectionInfo`, rien de plus à afficher - et c'est heureux, 37.6 ayant établi que le panneau de
  connexion est déjà à la limite du lisible avec trois formes.
- **Configuration dynamique.** Les routeurs viennent du provider HTTP, repollé toutes les 5 s. Donc
  **aucun redémarrage du proxy**, aucune fenêtre calme, aucun des pièges de l'ordre de bascule de
  37.1/37.3. Le changement s'applique aux runs déjà en cours, et le retour arrière est un revert de
  l'API.

### Décision à prendre au démarrage de la story : interrupteur ou pas

Faut-il un réglage (`TRAEFIK_ALLOW_PLAINTEXT`, comme `$certResolver` est déjà configurable) pour
couper le chemin en clair sans revert de code ?

- **Pour** : c'est la seule surface publique en clair d'une run après l'epic ; pouvoir la fermer
  depuis la configuration en cas d'incident, sans release, a de la valeur.
- **Contre** : un drapeau que personne ne bascule jamais est un mode de test de plus et une
  divergence prod/dev de plus. 37.3 en a déjà introduit deux (`AP_PUBLISH_HOST_PORT`,
  `PROXY_NETWORK`).

Recommandation : **oui, avec valeur par défaut activée**, parce que le chemin en clair est la seule
chose de cet epic qui puisse devoir être coupée en urgence, et que le coût est un argument de
constructeur. À trancher avant Task 2, pas pendant.

## Acceptance Criteria

### Prérequis - à lever avant toute écriture de code

1. **Vérifié sur le banc local** qu'un routeur TCP TLS et un routeur TCP non-TLS peuvent coexister
   sur le même entrypoint Traefik v3, sans conflit ni avertissement, et que chacun reçoit bien le
   trafic de son schéma. Le banc est celui qui a validé la chaîne complète le 2026-08-13. **Si
   cette vérification échoue, la story s'arrête et est réécrite** : le repli est une seconde plage
   de ports publiée en clair (changement orchestrateur, pare-feu, release d'image), qui n'a ni le
   même périmètre ni le même risque. Ne pas improviser ce repli dans cette story.

### Génération de la configuration

2. `TraefikConfigBuilder::build()` produit **deux routeurs par session en cours** : celui qui existe
   aujourd'hui, inchangé, et un routeur sans bloc `tls` sur le **même entrypoint**, pointant vers le
   **même service**. Aucun service supplémentaire n'est créé.
3. Le routeur en clair porte la même règle ``HostSNI(`*`)`` - la seule autorisée pour un routeur TCP
   non-TLS, faute de SNI à lire.
4. La clé du routeur en clair ne peut pas entrer en collision avec celle d'une autre session. Les
   identifiants de session sont des chaînes hexadécimales, mais le suffixe ne doit pas reposer sur
   cette propriété : le test le vérifie explicitement.
5. Le routeur TLS existant est **strictement inchangé** : certresolver, `domains`, entrypoint,
   service. Les tests unitaires actuels de `TraefikConfigBuilderTest` passent sans modification.

### Vérification de bout en bout

6. Sur une run réelle, les deux schémas aboutissent au même serveur Archipelago : `wss://{hôte}:{port}`
   (non-régression) **et** `ws://{hôte}:{port}` (l'objet de la story). Le contrôle est protocolaire,
   pas seulement une socket ouverte : la réception d'un `RoomInfo` fait foi, comme en 37.6.
7. **Au moins un mod ws-only nommé** est testé, avec sa version, et le résultat est consigné. Un
   verdict sans client nommé ne vaut rien : c'est exactement la leçon de 37.6, et c'est en n'ayant
   testé que le client desktop que l'epic a produit cette régression.

### Documentation

8. L'affirmation « TLS uniquement » ne subsiste **nulle part ailleurs** dans le dépôt. La correction
   de l'epic (faite le 2026-08-15) ne suffit pas si `docs/traefik-runs-archipelago.md`,
   `docs/deploiement-production.md`, le `README`, le `CHANGELOG` ou une autre story de l'epic 37 la
   répètent. Le contrôle est une recherche, pas une supposition, et chaque occurrence est corrigée
   ou datée. Laisser une doc affirmer une décision que le code ne suit pas est exactement le défaut
   que l'epic 33 a passé son temps à éliminer.
9. Le tableau `Surface publique d'une run` de l'epic est mis à jour : la ligne du serveur Archipelago
   n'est plus « TLS » mais « TLS **et** clair, sur le même port », avec la mention que le trafic en
   clair expose le nom de slot et le mot de passe Archipelago. Décision écrite, pas effet de bord.
10. La table de diagnostic de `docs/traefik-runs-archipelago.md` est corrigée : une connexion en
    clair sur un port de run ne répond plus 404, elle est routée. Cette ligne est le premier réflexe
    de diagnostic, elle doit dire la vérité d'après cette story.

### Gates

11. `composer gates` passe, y compris `app:architecture:ddd` (le builder reste en
    `Sessions/Application/Support/`).

## Tasks / Subtasks

- [x] **Task 1** (AC 1). Banc local : deux routeurs sur un entrypoint, un client TLS et un client
  clair. **Levée le 2026-08-15 - voir « Résultat du banc » ci-dessous. Le repli par seconde plage de
  ports est écarté.**
- [x] **Task 2** (AC 2-5). Second routeur dans `TraefikConfigBuilder`. **Interrupteur écarté** par
  Jean le 2026-08-15 : le routeur clair est toujours généré, et fermer le clair passe par un revert
  de l'API - un déploiement normal, effet en 5 s.
- [x] **Task 3** (AC 2-5). Tests unitaires : deux routeurs par session, absence de clé `tls` sur le
  routeur clair, service partagé et unique, non-collision des clés, non-régression du routeur TLS.
- [ ] **Task 4** (AC 6-7). Vérification en conditions réelles, mod nommé et versionné.
  **Non faisable hors production - c'est le seul reste de la story.**
- [x] **Task 5** (AC 8-10). Amendements de l'epic, de la story 37.3 et de
  `docs/traefik-runs-archipelago.md`.
- [x] **Task 6** (AC 11). `composer gates` vert (1814 tests).

## Résultat du banc (AC 1, 2026-08-15)

**Traefik accepte les deux routeurs sur le même entrypoint, et route chacun selon le schéma du
client.** Mesuré sur `traefik:v3.6.25` (`ramequin`), la majeure de production, avec exactement la
configuration que produira la story : deux routeurs ``HostSNI(`*`)`` sur `ap-35042`, l'un avec bloc
`tls`, l'autre sans, pointant tous deux vers le **même** service.

Un vrai upgrade WebSocket aboutit sur les deux chemins, vers le même backend :

```
ws://localhost:35042/.ws    -> HTTP/1.1 101 Switching Protocols
wss://localhost:35042/.ws   -> HTTP/1.1 101 Switching Protocols
```

Aucun `WARN`, aucun `ERROR`, aucun message de conflit ou de doublon : les deux routeurs sont chargés
et servis simultanément. Le contrôle a porté sur un upgrade WebSocket réel et pas seulement sur un
`GET`, le serveur Archipelago parlant nativement WebSocket.

**Ce que le banc ne prouve pas, et pourquoi c'est volontaire :**

- il utilise un **certificat auto-signé par défaut** plutôt que `certResolver` + `domains`. Le
  muxage TLS/clair est décidé avant toute sélection de certificat : le chemin de production est
  déjà validé depuis le 2026-08-14, et le rejouer ici n'aurait rien ajouté ;
- il utilise le provider **file** plutôt que le provider **http**. La question portait sur la
  sémantique des routeurs, pas sur leur acheminement, et le provider HTTP est en production depuis
  le 2026-08-14.

**Piège du banc, sans effet en production** : `--providers.file.watch=true` ne voit pas les
modifications d'un bind-mount sous Docker Desktop Windows. Un `docker compose restart traefik` est
nécessaire après chaque édition, faute de quoi on teste l'ancienne configuration en croyant tester
la nouvelle - ce qui est arrivé une fois pendant cette mesure. La production n'est pas concernée :
elle repolle le provider HTTP toutes les 5 s.

## Dev Notes

- **Le trafic en clair revient, et c'est le prix assumé.** Nom de slot et mot de passe Archipelago
  transitent en clair, sur un port d'une plage bornée d'un hôte connu. Ce n'était pas le moteur de l'epic - le
  déclencheur était la règle de contenu mixte des navigateurs, pas la confidentialité - et c'était
  l'état d'avant le 2026-08-14. La fermeture par 37.3 reposant sur une décision qui n'existait pas,
  il n'y a pas d'arbitrage à renverser ; il y en a un à **poser pour la première fois**, par écrit
  (AC 9), plutôt que de laisser le clair revenir en silence comme il était parti.
- **Ce que la story ne change pas** : le mot de passe Archipelago reste la seule barrière réelle
  d'une run, les WebSockets échappent toujours au CORS, et la plage de ports n'apporte toujours
  aucune obscurité. Ces trois points étaient déjà ouverts dans l'epic ; cette story n'en aggrave
  aucun, elle ne les ferme pas non plus.
- **Ne pas toucher au contrat API.** La tentation sera d'ajouter une `connectionUriInsecure` à côté
  de `connectionUri`. Elle n'a pas lieu d'être : le port est le même, et l'adresse `{hôte}:{port}`
  que 37.5 affiche déjà couvre le cas. Ajouter une quatrième forme au panneau de connexion
  aggraverait le problème que 37.6 a documenté, pour rien.
- **Le mode de défaillance à surveiller après livraison** : un client qui *pourrait* faire du `wss`
  mais retombe silencieusement en `ws` fonctionnera désormais, et personne ne le saura. On perd un
  signal d'erreur en échange de la compatibilité. C'est acceptable ici, mais ça veut dire que le
  chemin chiffré ne peut plus être considéré comme vérifié par « ça marche chez les joueurs ».
- **Précédent de nommage** : `ENTRYPOINT_PREFIX` documente déjà un contrat non testable avec
  `scripts/gen-traefik-entrypoints.sh`. Le suffixe du routeur clair, lui, est purement interne à
  la configuration produite - il n'a de contrat avec personne, et n'a donc pas besoin du même
  niveau de commentaire.

### Déploiement

Rien à faire sur le proxy. Le provider HTTP repolle la configuration toutes les 5 s : un déploiement
API normal suffit, et il s'applique aux runs déjà en cours. Aucune fenêtre calme, aucun ordre de
bascule, contrairement à 37.1 et 37.3 qui touchaient la configuration statique.

**Flux git à trancher avec Jean.** L'impact joueur est celui d'une régression de production active
depuis le 2026-08-14, ce qui plaide pour le flux hotfix (branche depuis `main`, PR vers `main` et
vers `develop`). L'argument contraire est que la story ajoute un comportement plutôt qu'elle ne
restaure du code supprimé. Recommandation : **hotfix**, la story servant de trace pour l'epic.

### Project Structure Notes

- `api/src/Sessions/Application/Support/TraefikConfigBuilder.php` - le seul fichier de production.
- `api/tests/Unit/Sessions/TraefikConfigBuilderTest.php` - tests existants conservés, nouveaux ajoutés.
- `api/config/services.yaml` - uniquement si l'interrupteur de la section « Décision » est retenu.
- `docs/traefik-runs-archipelago.md`, `_bmad-output/planning-artifacts/epics/epic-37-*.md` - AC 8-10.
- **Rien** dans `frontend/`, rien dans le dépôt `orchestrateur`, rien dans `bridge/`.

### References

- [Source: _bmad-output/planning-artifacts/epics/epic-37-acces-wss-aux-serveurs-archipelago.md] - décision « TLS uniquement » corrigée le 2026-08-15, tableau de la surface publique
- [Source: _bmad-output/implementation-artifacts/37-3-fin-de-l-exposition-publique-du-port-ap.md] - la fermeture du port hôte qui a produit la régression
- [Source: _bmad-output/implementation-artifacts/37-6-matrice-compatibilite-clients-web-tiers.md] - la méthode : un verdict par client nommé et daté
- [Source: docs/traefik-runs-archipelago.md] - table de diagnostic à corriger
- [Source: api/src/Sessions/Application/Support/TraefikConfigBuilder.php] - routeur TLS existant

## Dev Agent Record

### Agent Model Used

claude-opus-5

### Completion Notes List

**Clés de routeurs : deux préfixes, pas un suffixe.** `run-{id}` pour le chiffré (inchangé),
`plain-{id}` pour le clair. Un suffixe aurait paru plus naturel et aurait été faux : la clé claire
de la session `x` vaudrait `run-x-plain`, soit exactement la clé chiffrée d'une session nommée
`x-plain`, et un run en écraserait silencieusement un autre. Avec deux préfixes distincts, aucune
clé d'une famille ne peut valoir une clé de l'autre, **quel que soit le contenu des identifiants** -
la propriété ne repose pas sur le fait que les identifiants de session sont hexadécimaux. Un test
couvre le cas adverse.

**Le service n'est pas dupliqué.** Les deux routeurs pointent vers le même service `run-{id}` : un
seul backend, deux portes d'entrée. Un test vérifie qu'il n'existe qu'un service par session.

**Interrupteur écarté** (décision de Jean, 2026-08-15). Argument retenu : fermer le clair par
configuration n'est pas plus rapide qu'un revert de l'API, et un drapeau que personne ne bascule
jamais coûte un mode de test et une divergence prod/dev permanents - 37.3 en a déjà introduit deux.

**AC 8 : le balayage a trouvé trois occurrences réelles hors epic**, toutes corrigées - l'objectif
de la story 37.3 (« trafic joueur chiffré de bout en bout »), l'entrée de change log du 2026-08-08
de l'epic, et l'introduction de `docs/traefik-runs-archipelago.md`. En revanche
`envs/orchestrateur.env.example` et la formule « Traefik est la seule socket publique d'une run »
restent **vraies** et n'ont pas été touchées : le proxy demeure la seule socket publique, il en sert
simplement deux schémas.

**La table de diagnostic gagne une ligne qui n'existait pas.** Les deux schémas empruntant des
routeurs distincts, l'un peut tomber pendant que l'autre répond. Un 404 en clair seul, TLS
fonctionnel, désigne maintenant un symptôme précis : le routeur `plain-{sessionId}` manque dans la
configuration servie par l'API. Sans cette ligne, une panne n'affectant que les mods serait
invisible au diagnostic habituel, qui ne teste que le `wss`.

**Reste la Task 4**, non faisable hors production : la vérification de bout en bout sur une run
réelle avec un mod ws-only nommé et versionné (AC 6-7). Le banc local prouve que Traefik route les
deux schémas ; il ne prouve pas qu'un mod donné se connecte.

### File List

- `api/src/Sessions/Application/Support/TraefikConfigBuilder.php` - routeur clair, préfixes de clés
- `api/tests/Unit/Sessions/TraefikConfigBuilderTest.php` - 5 tests ajoutés, 6 existants inchangés
- `docs/traefik-runs-archipelago.md` - double schéma, table de diagnostic, commandes des deux chemins
- `_bmad-output/planning-artifacts/epics/epic-37-*.md` - décision corrigée, surface publique, change log
- `_bmad-output/implementation-artifacts/37-3-*.md` - objectif amendé

## Change Log

| Date | Change |
|------|--------|
| 2026-08-15 | Créée. Déclencheur : des mods de jeu faisant office de client Archipelago sont ws-only, donc exclus des runs depuis le 2026-08-14. La décision « TLS uniquement » sur laquelle 37.3 s'appuyait **n'avait jamais été prise** - erreur de rédaction de l'epic, confirmée par Jean le 2026-08-15 et corrigée dans l'epic le jour même. L'intention a toujours été de servir les deux schémas sur le même port. Le sniffing que la section fautive écartait est natif dans Traefik : le périmètre se réduit à un second routeur, sans changement de contrat, d'affichage, ni de configuration statique. |
