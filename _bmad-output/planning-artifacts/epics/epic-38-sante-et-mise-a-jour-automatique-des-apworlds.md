# Epic 38: Santé et mise à jour automatique des apworlds

**Statut :** en cours - stories rédigées le 2026-09-24, 38.1 et 38.2 en review
**Date :** 2026-09-24
**Origine :** incident Crystal Project du 2026-09-24 - tout slot Crystal Project en YAML vierge échouait
au test de génération, et personne ne l'avait vu.

## Objectif

Qu'un apworld cassé soit **vu, suivi et remplacé** sans qu'un joueur ait à tomber dessus :

1. un incident persistant et une alerte dès qu'un apworld échoue à sa génération par défaut ;
2. une mise à jour quotidienne automatique des apworlds depuis GitHub, qui ne bascule un jeu que si
   la nouvelle version **passe** le test de génération ;
3. un test tournant du catalogue, qui revérifie régulièrement chaque jeu et en priorité après un
   changement d'image Archipelago.

## Le déclencheur (diagnostiqué le 2026-09-24)

L'apworld Crystal Project **v0.17.0**, déployé le 2026-07-16, a un bug de logique : les lieux de
Castle Ramparts et de Castle Sequoia sont hors logique. La génération solo avec le YAML par défaut
échoue sur **100 % des seeds** (8 sur 8, `Fill.FillError: Could not access required locations`).
L'auteur l'a corrigé en **v0.18.0** (« Fixed an issue with castle ramparts checks not being in logic
when they should »), sortie le 2026-09-06. La v0.18.2 passe 40 seeds sur 40 sur l'image de prod.

Ce qui a mal tourné, dans l'ordre :

- Le test d'intégration de la story 9.38 a très probablement échoué dès l'import de juillet : il échoue
  à chaque seed. Mais un échec ne produit **qu'un badge** sur la page admin du jeu. Aucune alerte.
- La veille des versions (story 14.5) ne tourne **qu'à la main**. En local, elle indiquait encore 0.16.0
  comme « dernière version » alors que la 0.18.2 existait depuis le 2026-09-11.
- L'import manuel **bascule le jeu avant la fin du test** : rien n'empêche un apworld cassé de
  devenir la version servie.
- Les slots déjà créés restent **figés** sur l'ancien hash (story 3.10, AC3) : même après le bon
  import, un joueur qui avait déjà ajouté le jeu reste sur la version cassée.

## Constat de départ (vérifié dans le code, 2026-09-24)

- **Verdicts de test :** l'orchestrateur lance le test à chaque téléversement
  (`orchestrateur/internal/service/service.go`, `RunApworldPreflight`) et le stocke dans le sidecar
  `{hash}.json`. L'API le **lit à la demande** (`RunnerGateway::fetchApworldPreflights()`) à
  l'affichage de la page admin. Rien n'est stocké côté API, aucun webhook n'existe pour les apworlds.
- **Veille :** `ApworldVersionChecker` et `CheckApworldUpdatesService` existent, exposés par
  `app:check-apworld-updates` et `POST /api/v1/admin/catalog-sync/check-updates`. Absents de
  `api/src/Schedule.php`. La comparaison de version est une égalité de chaînes après `ltrim('vV')` :
  une release plus ancienne serait vue comme une mise à jour.
- **Import :** `AdminGameLibrary::configureApworld()` (upload) et `importFromGithub()` appellent
  `Game::configureApworld()` immédiatement, qui écrase hash, clé de stockage et YAML par défaut.
- **Historique :** MinIO et l'orchestrateur sont adressés par hash et ne suppriment rien. Tout ancien
  hash reste disponible, ce qui rend un retour arrière possible sans stockage supplémentaire.
- **Notifications :** `Community\Application\Support\Notifier` (in-app) et
  `CommunityAdminIdsQueryInterface` (ids des admins, story 30.28) existent. Aucun Discord sortant :
  `DiscordBotClient` ne gère que des rôles.
- **Image Archipelago :** en prod, `AP_IMAGE` est déjà une référence versionnée
  (`ghcr.io/archilan-dev/archipelago:0.x.y`, `.env.prod.example:69`). En local, `archipelago:latest`.

## Décisions (Jean, 2026-09-24)

| Question | Décision |
|---|---|
| Portée de la mise à jour automatique | **Tous les jeux** dont la source est un dépôt GitHub, **sans période de gel** autour des events |
| Garde-fou | Un jeu ne bascule que si la nouvelle version **passe** le test de génération par défaut |
| Import manuel | **Même circuit** que la mise à jour auto (candidat, test, promotion), avec un forçage admin |
| Canaux d'alerte | **Notification in-app aux admins** + **webhook Discord** vers un salon staff + **page Santé des apworlds**. Pas d'email |
| Suivi | Un incident persistant, avec un admin qui le prend en charge (« un tel s'en occupe ») |
| Slots existants | Un slot suit la version courante du jeu **tant que sa run n'est pas lancée**, puis se fige |
| YAML d'un slot à la mise à jour | Ne **jamais** remplacer un YAML que le joueur a personnalisé s'il reste valable. Le prévenir s'il ne l'est plus |
| YAML qui n'est plus valable | La version de l'apworld est **quand même** mise à jour (l'ancienne peut être la cassée), le YAML est conservé et le slot marqué « à revoir » |
| Retest | **Pas** de retest global à chaque image. Un **test tournant** en décalé, par petits lots quotidiens, avec priorité aux jeux testés sur une ancienne image |

### Décisions techniques prises à la rédaction

| Question | Décision | Pourquoi |
|---|---|---|
| Remontée des verdicts vers l'API | **Réconciliation planifiée** (lecture périodique des verdicts), pas de webhook | Les webhooks orchestrateur sont fire-and-forget sans retry : un événement perdu est une alerte perdue. Une réconciliation idempotente rattrape tout au passage suivant, et ne touche qu'un dépôt. |
| Contexte DDD | **`GameSelection`**, pas de nouveau contexte | L'apworld, son hash et son verdict vivent déjà là (`Game`, `AdminGameLibrary`). Un nouveau contexte coûterait quatre dossiers, une entrée dans `DddArchitectureValidator::CONTEXTS` et une frontière de plus pour un seul agrégat. |
| Dédoublonnage | Un seul incident **actif** par (jeu, hash, type) | Un apworld qui échoue chaque nuit fait un incident, pas trente alertes. |
| Version de l'image | Référence `AP_IMAGE` + identifiant de l'image (`docker inspect`) | La référence suffit en prod, l'identifiant rend `latest` exploitable en local et détecte un re-push sur un même tag. |

## Démarche : TDD et DDD

Demandé explicitement pour cet epic. Chaque story porte une section **Ordre TDD** qui liste les tests à
écrire **avant** le code, dans l'ordre. La règle :

1. **Rouge** : écrire le test, le lancer, constater qu'il échoue **pour la bonne raison** (pas une
   erreur de syntaxe ou une classe absente quand on teste un comportement).
2. **Vert** : le minimum de code pour le faire passer.
3. **Refactor** : nettoyer avec les tests au vert, puis `composer gates` / `pnpm gates`.

Côté DDD, on part du domaine : les règles (transitions d'un incident, classement d'un YAML, ordre de
priorité du test tournant, comparaison de versions) sont des **méthodes pures** du domaine, testées en
unitaire sans kernel ni base. L'Application orchestre, l'Infrastructure implémente les ports.
Aucune horloge, aucun hasard, aucun identifiant généré dans le domaine : tout arrive en paramètre
(`api/CLAUDE.md`, AC-D3).

## Découpage en stories

| # | Story | Bloc | Dépend de |
|---|---|---|---|
| 38.1 | [Incidents apworld](../../implementation-artifacts/38-1-incidents-apworld.md) | A - alerte | - |
| 38.2 | [Canaux d'alerte : admins in-app et Discord](../../implementation-artifacts/38-2-canaux-alerte-admins-et-discord.md) | A - alerte | 38.1 |
| 38.3 | [Page Santé des apworlds](../../implementation-artifacts/38-3-page-sante-des-apworlds.md) | A - alerte | 38.1 |
| 38.4 | [Incidents issus des vraies générations](../../implementation-artifacts/38-4-incidents-issus-des-generations.md) | A - alerte | 38.1 |
| 38.5 | [Veille quotidienne des versions](../../implementation-artifacts/38-5-veille-quotidienne-des-versions.md) | B - mise à jour | - |
| 38.6 | [Mise à jour en trois temps](../../implementation-artifacts/38-6-mise-a-jour-en-trois-temps.md) | B - mise à jour | 38.1, 38.5 |
| 38.7 | [Slots des runs non lancées](../../implementation-artifacts/38-7-slots-des-runs-non-lancees.md) | B - mise à jour | 38.6 |
| 38.8 | [Version de l'image dans les verdicts](../../implementation-artifacts/38-8-version-image-dans-les-verdicts.md) | C - surveillance | - |
| 38.9 | [Test tournant du catalogue](../../implementation-artifacts/38-9-test-tournant-du-catalogue.md) | C - surveillance | 38.1, 38.8 |

**Ordre de livraison conseillé :** 38.1, 38.2, 38.3 (l'alerte est utile seule et le reste en dépend),
puis 38.5, 38.6, 38.7, puis 38.8, 38.9. 38.4 peut se glisser n'importe où après 38.1.

## Dépôts touchés

| Dépôt | Stories |
|---|---|
| monorepo (`api/`, `frontend/`) | toutes |
| `orchestrateur` | 38.8 |
| `archilan-orchestrateur-client` (`packages/orchestrateur-client`) | 38.8 |

Aucune modification de l'image `archipelago` n'est nécessaire : la référence `AP_IMAGE` suffit.

## Risques et points de vigilance

- **Mod client.** Sans gel, une mise à jour automatique peut changer la version du mod client exigée
  par un jeu la veille d'un event (c'est le cas de Crystal Project entre 0.17 et 0.18). Décision assumée.
  38.2 annonce chaque promotion sur Discord pour que le staff puisse prévenir les joueurs.
- **Fausse confiance.** Un test solo avec le YAML par défaut ne prouve pas qu'un YAML de joueur passe.
  Il prouve seulement que l'apworld n'est pas cassé dans sa configuration de base, ce qui est
  exactement le cas Crystal Project.
- **Seeds malchanceuses.** Le test tire une seed au hasard. Un apworld qui échoue sur une seed sur
  cinquante ne doit pas réveiller le salon Discord : 38.9 exige une confirmation avant d'alerter
  sur une régression.
- **Charge.** Un test prend de 1 à 5 minutes de conteneur. La limite de concurrence de l'orchestrateur
  (`PreflightMaxConcurrent`) borne déjà la charge ; le test tournant reste par petits lots.
- **Dépôts à plusieurs apworlds.** Le choix automatique de l'asset n'est sûr que s'il n'y a pas
  d'ambiguïté. Dans le doute, 38.6 ne met pas à jour et ouvre un incident à arbitrer.

## Change Log

| Date | Description |
|---|---|
| 2026-09-24 | Création de l'epic après l'incident Crystal Project. Décisions de Jean, découpage en 9 stories. |
