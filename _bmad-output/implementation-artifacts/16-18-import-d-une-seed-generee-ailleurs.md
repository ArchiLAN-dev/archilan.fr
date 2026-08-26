# Story 16.18: Créer une partie privée à partir d'une seed déjà générée

**Status:** à implémenter - en attente de relecture
**Epic:** 16 - Personal runs (parties privées créées par un membre)
**Date:** 2026-08-25
**Dépend de :** [16.17](16-17-co-joueurs-sur-un-slot.md) - l'assignation des slots de l'archive aux
participants réutilise l'association posée là

## Story

En tant que membre,
je veux créer une partie privée à partir d'une seed générée ailleurs,
afin d'héberger sur ArchiLAN un multiworld qu'on ne veut pas regénérer.

## Context

Une partie privée passe aujourd'hui par la génération : chaque participant déclare ses jeux et son
YAML, l'orchestrateur lance un conteneur de génération, et le multiworld produit devient la sortie
de la session.

Or une seed peut déjà exister : générée sur le site Archipelago, par un membre en local, ou par un
autre groupe. Elle est alors un fait accompli, et la regénérer donnerait un autre multiworld.

### Héberger ne demande que la multidata

Notre propre lanceur ne prend rien d'autre :

```sh
GAME_FILE=$(ls "$GAME_DIR"/*.zip "$GAME_DIR"/*.archipelago 2>/dev/null | head -1)
...
exec ArchipelagoServer "$@"
```

Ni YAML, ni apworld. Le bridge non plus n'en a pas besoin, ni la lecture des sauvegardes, ni les
patchs, qui sont dans l'archive.

### Sauf le tracker

Un seul consommateur en aval a besoin d'autre chose : `reachable.py`, qui **régénère** le monde pour
calculer les checks accessibles. Il lui faut le YAML de chaque joueur

```python
yaml_candidates = list(Path(args.yamls).glob(f"{player_name}.yaml"))
...
_emit({"error": f"no yaml found in {args.yamls}"}); sys.exit(1)
```

et l'apworld du jeu, faute de quoi il meurt sur « No world found to handle game X ».

Or une archive de sortie Archipelago **ne contient pas les YAML** - vérifié sur nos propres archives,
qui portent la multidata, les patchs et le spoiler, rien d'autre. Et les apworlds ne s'ajoutent que
par un administrateur : rien ne garantit que celui de la seed importée soit dans le pool.

La conclusion est donc nette, et sans cas particulier : **une seed importée n'a pas de progression
détaillée.** Pas de « ça marche si le jeu est au catalogue », qui produirait une fonctionnalité
imprévisible.

### Ce qui marche quand même

| ce qui vient du bridge | sur une seed importée |
|---|---|
| checks faits / total, items reçus, statut, goal | ✅ |
| grille de progression, feed en direct, timeline | ✅ |
| indices : liste, achat, priorités | ✅ |
| locations d'un objet | ✅ |
| patchs, sauvegardes, récap de fin | ✅ |

| ce qui vient de la reachability | |
|---|---|
| « X faisables » sur les cartes de slot | ❌ |
| listes des checks accessibles / inaccessibles | ❌ |
| sphères | ❌ |
| détail des items reçus / restants | ❌ |

Une seed importée reste donc jouable et suivie. Ce qu'on perd, c'est l'aide à la décision.

### Le bridge n'a plus de slot à lui

Tout ce tableau repose sur le bridge, et le bridge se connecte au multiworld comme ceci :

```python
first_slot = self._config.slot_names[0] if self._config.slot_names else {}
connect_name = first_slot.get("name", "Bridge")
```

Sur nos seeds, `slot_names[0]` est l'observateur injecté par l'orchestrateur, qui trie avant tout
nom de joueur. Une seed importée n'en a pas : le bridge se connecterait **en tant que premier vrai
joueur**. Archipelago l'accepte - plusieurs clients peuvent tenir le même slot, et le bridge se
connecte en `TextOnly` avec `items_handling: 0`, donc il ne touche à rien.

Deux effets de bord, en revanche : ce joueur apparaîtrait **connecté en permanence**, et le filtre
qui écarte le slot observateur (jeu `Archipelago`) ne filtrerait plus rien.

### La multidata n'est pas un vecteur d'exécution

Une multidata est un pickle, et `pickle` exécute du code à la désérialisation. Mais Archipelago la
charge par `restricted_loads` (`MultiServer.py:493`), un `RestrictedUnpickler` à allowlist : les
`builtins` sûrs, `collections.Counter`, six types de `NetUtils`, les classes d'options. Tout le
reste lève `UnpicklingError`.

Héberger une seed importée n'est donc **pas** de la même classe de risque qu'un apworld custom.
C'est ce qui rend cette story acceptable pour un membre non administrateur, là où l'import d'apworld
ne l'est pas.

### Décisions de cadrage (Jean, 2026-08-25)

| Question | Décision |
|---|---|
| Progression détaillée | **Indisponible**, et **dite** par un bandeau explicite. Masquer sans expliquer ressemblerait à une panne. |
| Bandeau | **Uniquement** sur une seed importée. L'assignation de slots, elle, n'a rien à voir et vaut pour toutes les parties. |
| Slots | Ils viennent de l'archive. Le **créateur de la run** les assigne aux participants, à plusieurs si besoin (story 16.17). |
| Apworlds | **Aucun import d'apworld par un membre.** Ils restent réservés aux administrateurs ; c'est ce qui garde la surface d'exécution de code tiers sous contrôle. |
| Bridge | Il se connecte **sur le slot du premier joueur**, et sa connexion est **distinguée** de celle du joueur pour que la présence reste juste. Refuser les seeds sans observateur aurait fermé la porte à presque toutes les seeds externes, donc à la feature. |
| Points | Une partie importée **compte comme les autres**. Le risque de seed triviale est assumé. |

## Acceptance Criteria

### Import

1. Le créateur d'une partie téléverse une archive de sortie Archipelago (`.zip`) ou une multidata
   nue (`.archipelago`). Le fichier est validé avant toute création : une archive sans multidata
   lisible est refusée avec un message qui dit pourquoi.
2. Les slots de la partie sont lus dans la multidata (`slot_info` : numéro, nom de slot, jeu). Ils
   ne sont ni devinés ni saisis à la main.
3. Le slot observateur du bridge, s'il est présent dans l'archive, n'est pas proposé à
   l'assignation : ce n'est pas un joueur.
4. L'archive importée devient la sortie de la session, et **la génération est sautée**. Aucun
   conteneur de génération n'est lancé, aucun YAML n'est demandé, aucun apworld n'est requis.
5. Une taille maximale est appliquée au téléversement, et l'archive est traitée comme une donnée
   hostile : pas de décompression vers un chemin arbitraire, pas de dépicklage dans le process de
   l'API, pas d'exécution. La lecture des métadonnées se fait dans le conteneur Archipelago jetable,
   comme le reste.

### Assignation

6. Le créateur assigne chaque slot de l'archive à un ou plusieurs participants, en réutilisant
   l'association de la story 16.17. Un slot peut rester non assigné : une seed peut contenir des
   slots que personne dans la run ne joue.
7. Un participant assigné à un slot en a le patch, les indices et les locations d'objets, comme sur
   une partie générée sur le site.
8. L'assignation est modifiable tant que la partie n'est pas terminée : quelqu'un peut rejoindre en
   cours de route.

### Lancement et suivi

9. La partie se lance, s'arrête, se met en veille et se reprend comme n'importe quelle autre. Le
   bridge se connecte en `TextOnly` sur le premier slot de la seed, sans perturber le joueur qui le
   tient : ni items reçus, ni checks, ni indices touchés.
10. La connexion du bridge est **distinguée** de celle d'un client de joueur. Un slot dont seul le
    bridge est connecté n'est pas affiché comme étant joué en ce moment - sur une seed importée
    comme sur une seed générée ici, où le repérage par le jeu `Archipelago` cesse d'être le seul
    critère.
11. Tout ce qui vient du bridge fonctionne : progression chiffrée, feed, timeline, indices,
    locations d'objets, patchs, sauvegardes, récap.
12. Les endpoints de reachability sont **désactivés** pour une partie importée, et répondent de
    manière explicite plutôt que de tomber sur une erreur de génération. Aucun calcul n'est lancé,
    aucun conteneur n'est exécuté pour rien.
13. Les onglets et éléments qui en dépendent sont masqués, et un **bandeau** explique que la
    progression détaillée n'est pas disponible sur une partie importée. Le bandeau ne s'affiche que
    dans ce cas.

### Points

14. Une partie importée compte comme les autres : les checks et les goals de ses slots alimentent
    XP, niveau, classement et succès, pour chaque joueur assigné au slot. Rien de spécifique à
    faire, mais à vérifier plutôt qu'à supposer.

    Contrepartie assumée : la seed n'a pas été générée ici, donc rien n'empêche d'en importer une
    triviale pour gonfler son XP. C'est un choix de confiance, cohérent avec « tout le monde
    marque » de la story 16.17, et non un angle mort. À rouvrir si quelqu'un en abuse.

### Gates

15. `composer gates` et `pnpm gates` verts. Tests : archive valide, archive sans multidata, archive
    corrompue, assignation et ré-assignation, refus de reachability sur une partie importée,
    présence correcte d'un slot où seul le bridge est connecté, fonctionnement des chemins bridge.

## Tasks / Subtasks

- [ ] **Task 1** (AC 1-5). Téléversement, validation, lecture des slots de la multidata, création de
  la session avec l'archive comme sortie et sans génération.
- [ ] **Task 2** (AC 6-8). Assignation des slots aux participants, sur l'association de 16.17.
- [ ] **Task 3** (AC 9-11). Connexion du bridge sans slot observateur, distinction de sa présence,
  cycle de vie, et vérification écran par écran de ce qui vient du bridge.
- [ ] **Task 4** (AC 12-13). Désactivation explicite de la reachability et bandeau.
- [ ] **Task 5** (AC 14-15). Points, tests et gates.

## Dev Notes

- **Le pivot est la clé de sortie de session.** Une session pointe vers son archive
  (`generatedOutputKey`, avec repli sur `{sessionId}/output/archive.zip`) ; c'est ce que lisent le
  lanceur, les patchs et la reachability. Importer, c'est déposer l'archive à cet endroit et marquer
  la session comme n'ayant pas été générée ici.
- **La multidata est un pickle zlib** dont `slot_info` donne, par numéro de slot, le nom et le jeu.
  Le serveur AP la charge par `restricted_loads`, donc l'héberger est sûr (voir le Context) - mais
  **notre** lecture des métadonnées n'a pas cette protection si elle se fait avec un `pickle.loads`
  ordinaire. La faire dans le conteneur Archipelago jetable, avec les outils d'AP, règle les deux
  problèmes d'un coup : l'allowlist s'applique, et le process de l'API ne touche jamais le fichier.
- **Le slot 1 est le bridge sur nos propres seeds**, parce que l'orchestrateur injecte un
  `_bridge_observer.yaml` qui trie avant tout nom de joueur. Le bridge s'appuie dessus sans le savoir
  (`slot_names[0]`, avec `"Bridge"` en repli) et rien ne vérifie que ce premier slot est bien un
  observateur. Sur une seed importée il ne l'est pas, et c'est le premier chantier de la story.
- **La distinction de présence est le vrai travail de la Task 3.** Le bridge se connecte déjà en
  `TextOnly` avec un `uuid` propre, et `_handle_packet` lit les `tags` des paquets : la matière est
  là. Ce qui manque, c'est que le calcul de présence cesse de traiter « un client est connecté sur
  ce slot » comme « ce joueur joue », et regarde qui est ce client. Le repérage actuel par jeu
  `Archipelago` disparaît en même temps, puisqu'il ne repose que sur le slot injecté.
- **Ne pas essayer de sauver la reachability.** Reconstruire les options depuis le `slot_data`, comme
  le fait Universal Tracker, ne marche que pour les apworlds qui l'implémentent, et la moitié ne le
  fait pas. Une progression détaillée qui marche pour certains jeux et pas pour d'autres serait pire
  qu'une absence assumée.
- **Ce que la story ne fait pas :** importer un apworld, accepter un YAML pour débloquer la
  progression, ni régénérer une seed à partir d'un numéro. Cette dernière piste est indépendante et
  déjà à moitié câblée : `generateSession()` accepte un `$seed` transporté jusqu'au générateur, et
  `SessionOrchestrator` passe `null` en dur.

## Change Log

| Date | Version | Description | Auteur |
|---|---|---|---|
| 2026-08-25 | 0.1 | Rédaction de la story | Claude |
| 2026-08-25 | 0.2 | Connexion et présence du bridge, points assumés, multidata non exécutable | Claude |
