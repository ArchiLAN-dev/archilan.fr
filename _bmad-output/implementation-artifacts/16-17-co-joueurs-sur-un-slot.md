# Story 16.17: Plusieurs joueurs sur un même slot

**Status:** implémentée - PR vers `develop`
**Epic:** 16 - Personal runs (parties privées créées par un membre)
**Date:** 2026-08-25
**Story suivante :** [16.18](16-18-import-d-une-seed-generee-ailleurs.md) - l'import d'une seed
réutilise l'association posée ici pour rattacher les slots de l'archive aux participants

## Story

En tant que participant d'une partie ArchiLAN,
je veux pouvoir jouer un slot à plusieurs,
afin qu'un jeu qui se joue naturellement en groupe compte pour tous ceux qui l'ont joué.

## Context

Un slot appartient aujourd'hui à une seule personne. `SessionSlot` porte une colonne
`registration_id` qui vaut l'userId du participant sur une run privée, et l'identifiant
d'inscription sur une session d'événement (`LaunchPersonalRunJobHandler`).

Or certains jeux ne se jouent pas seuls. Un Minecraft, c'est rarement une personne devant son
écran : trois joueurs sur le même monde, donc sur le même slot Archipelago. Aujourd'hui un seul
d'entre eux existe pour la plateforme.

### Ce que cette colonne commande

`registration_id` n'est pas un détail de stockage, c'est le pivot de **toutes** les autorisations
par slot et de **tout** le système de points :

| ce qui en dépend | conséquence pour un co-joueur aujourd'hui |
|---|---|
| `PersonalRunPatchQuery` | ne peut pas télécharger le patch du slot qu'il joue |
| `PlayerSessionConnection` | le slot n'apparaît pas dans ses infos de connexion d'événement |
| `SessionQuery::doesUserOwnSlot` | pas d'indices, pas d'achat d'indice, pas de priorités, pas de locations d'objets |
| `DbalPlayerStatsQuery` | aucune run, aucun goal, aucun check comptés |
| `DbalLeaderboardQuery` | absent du classement |
| `StatsMetricProvider` | aucun succès débloqué |

Les trois dernières lignes partent du même endroit : les composantes de l'XP sont agrégées par
`slot.registration_id`.

```sql
COUNT(DISTINCT s.id)              AS runs_participated
COUNT(slot.goal_reached_at)       AS goal_completions
SUM(slot.checks_done)             AS total_checks_done
```

Le classement public groupe sur la même colonne, et les succès lisent le même
`PlayerStatsQueryInterface`. Une seule requête à corriger, donc, mais elle irrigue XP, niveau,
classement et succès d'un coup.

**Ne rien décider, c'est décider que les co-joueurs marquent zéro** - et faire du partage de slot
une punition pour ceux qui rejoignent.

### Décisions de cadrage (Jean, 2026-08-25)

| Question | Décision |
|---|---|
| Nombre de co-joueurs | **Aucune limite.** |
| Points | **Tout le monde marque pleinement** : la run, les goals et les checks du slot comptent pour chaque joueur du slot. L'inflation du classement est assumée : dans une association de cette taille le classement est un jeu, et l'alternative viderait la fonctionnalité de son intérêt. |
| Propriété du slot | Le slot **garde un propriétaire**, celui qui a déclaré le jeu et sa configuration. Les co-joueurs héritent de l'accès sans pouvoir modifier le YAML : arbitrer des modifications concurrentes de configuration coûterait cher pour un gain nul. |
| Qui gère les co-joueurs | Le **propriétaire de la run**. |
| Portée | **Runs privées d'abord.** Le modèle et les autorisations sont communs aux deux surfaces, puisque la colonne est la même ; seule la **gestion** reste hors de portée côté événement, où il n'y a pas de propriétaire de run et où il faudrait un écran d'administration. Une story à part. |
| Affichage d'un slot partagé | **Tous les pseudos.** Le but de la story est que les co-joueurs existent à l'écran ; un compteur les remettrait au rang de seconds rôles. |

## Acceptance Criteria

### Modèle

1. Un slot de session porte un propriétaire et **zéro ou plusieurs co-joueurs**, sans limite de
   nombre. Le propriétaire reste ce qu'il est aujourd'hui, et un slot sans co-joueur se comporte
   exactement comme avant.
2. Un même joueur ne peut pas être co-joueur deux fois du même slot, ni co-joueur d'un slot dont il
   est déjà propriétaire.
3. Un co-joueur doit être un participant de la run (ou un inscrit de l'événement). On ne rattache
   pas quelqu'un d'extérieur à la partie.

> Le modèle et les autorisations valent pour les **deux** surfaces, parce que `registration_id` est
> la même colonne des deux côtés. Seule l'**interface de gestion** (AC 12) est limitée aux runs
> privées : côté événement, un slot partagé se comporterait correctement partout, mais rien ne
> permet encore d'en créer un. C'est voulu, pas un oubli.

### Autorisations

4. Un co-joueur télécharge le patch du slot qu'il joue, exactement comme son propriétaire.
5. Un co-joueur a accès aux indices du slot, à l'achat d'indice, aux priorités et aux locations
   d'objets. C'est la garde `doesUserOwnSlot` qui décide, et elle doit répondre vrai pour lui.
6. Un co-joueur voit le slot dans ses infos de connexion et sur sa page de progression, comme un
   slot à lui.
7. **Rien d'autre ne s'ouvre.** Un co-joueur n'obtient ni la configuration YAML du slot, ni les
   droits du propriétaire de la run, ni l'accès aux slots des autres. À vérifier écran par écran.

### Points, niveaux, classement, succès

8. Les checks et le goal d'un slot partagé comptent **pour chaque joueur du slot**, propriétaire
   comme co-joueurs. Une seule requête de statistiques alimente XP, niveau, classement et succès :
   la correction se fait là, pas en quatre endroits.
9. La run compte une fois par joueur, pas une fois par slot. `runs_participated` agrège des sessions
   distinctes ; un joueur présent sur deux slots d'une même run ne doit pas la voir compter deux
   fois.
10. Un slot libéré sans goal reste exclu du décompte pour tout le monde, co-joueurs compris : la
    garde existante ne doit pas être contournée par le nouveau chemin.
11. Le classement public inclut les co-joueurs, sur les deux axes (goals et checks).

### Interface

12. Le propriétaire de la run ajoute et retire des co-joueurs sur un slot, depuis la partie. Retirer
    quelqu'un lui retire l'accès **et** ses points sur ce slot ; c'est attendu et sans dommage,
    puisque les statistiques sont calculées et non stockées.
13. Un slot partagé montre qui le joue, à tous les participants de la run. Jouer à plusieurs n'est
    pas une information privée.
14. Un slot partagé est nommé par **tous** ses joueurs, partout où il l'est aujourd'hui par un seul :
    carte de fin de partie, cartes de slot, feed. `SessionSlotOwnersQuery` renvoie aujourd'hui **un**
    pseudo par nom de slot ; elle en renverra une liste, et les surfaces qui la consomment doivent
    rester lisibles quand cette liste est longue.
15. Les quotas de sélection de jeux ne comptent pas les co-joueurs : ils ne déclarent aucun jeu, ils
    rejoignent celui d'un autre.

### Gates

16. `composer gates` et `pnpm gates` verts. Tests : accès patch/indices/locations d'un co-joueur,
    refus d'un tiers, comptage des points pour propriétaire et co-joueur, non-régression complète
    d'un slot solo.

## Tasks / Subtasks

- [x] **Task 1** (AC 1-3). Modèle et migration : association entre un slot de session et ses
  co-joueurs, avec ses règles de domaine et leurs tests unitaires.
- [x] **Task 2** (AC 4-7). Les trois chemins d'autorisation par slot, et la revue écran par écran de
  ce qui ne doit pas s'ouvrir.
- [x] **Task 3** (AC 8-11). Statistiques : le co-joueur entre dans l'agrégat, une fois. Vérifier la
  propagation vers XP, niveau, classement et succès.
- [x] **Task 4** (AC 12-15). Interface de gestion côté run privée, affichage de tous les joueurs d'un
  slot partagé, quotas.
- [x] **Task 5** (AC 16). Tests et gates des deux côtés.

## Dev Notes

- **Le pivot est `session_slot.registration_id`.** Trois requêtes le lisent pour autoriser
  (`PersonalRunPatchQuery`, `PlayerSessionConnection`, `SessionQuery::doesUserOwnSlot`) et deux pour
  compter (`DbalPlayerStatsQuery`, `DbalLeaderboardQuery`). Commencer par la liste exhaustive de ses
  lecteurs avant d'écrire quoi que ce soit : en oublier un donne un co-joueur à moitié reconnu, ce
  qui est pire que pas reconnu du tout.
- **Ne pas élargir `registration_id` lui-même.** La colonne identifie qui a *déclaré* le slot ;
  c'est elle qui relie le slot à la configuration et au YAML. Les co-joueurs sont une association à
  côté, pas une réécriture de cette colonne.
- **`doesUserOwnSlot` vient d'être refaite** (story de correction du 2026-08-21) : elle résout le
  numéro de slot Archipelago vers son nom de slot via le `session_players_snapshot`, puis compare le
  `registration_id`. C'est ce dernier point qui s'élargit, pas la résolution.
- **Les succès ne se recalculent pas tout seuls.** `StatsMetricProvider` lit le même
  `PlayerStatsQueryInterface`, donc les faits changent dès la correction, mais l'octroi des succès
  déjà évalués suit sa propre mécanique. Vérifier qu'un co-joueur ajouté sur une partie terminée
  finit bien par obtenir ce à quoi il a droit.
- **`SessionSlotOwnersQuery` passe de un pseudo à une liste.** Elle résout un nom de slot vers un
  pseudo via `CommunityUserDirectoryQueryInterface::namesFor()`. Sa signature change, donc tous ses
  appelants aussi : les traiter d'un bloc plutôt que d'ajouter une seconde requête à côté.
- **Ce que la story ne fait pas :** permettre à un co-joueur de modifier la configuration du slot, de
  lancer ou d'arrêter la partie, de céder la propriété d'un slot, ni gérer des co-joueurs sur une
  session d'événement.

## Écarts assumés

### L'association pend au slot de jeu, pas au slot de session (AC 1)

La story parlait d'une « association entre un slot de session et ses co-joueurs ». À
l'implémentation, `SessionSlot` s'est révélé le mauvais point d'accroche : ces lignes n'existent
qu'**après** le lancement, et un redémarrage les jette pour en recréer d'autres. Accrocher les
co-joueurs là aurait rendu l'assignation impossible avant le lancement - alors qu'un Minecraft à
trois se décide en choisissant les jeux - et l'aurait perdue à chaque relance.

`slot_co_player` pointe donc vers l'**identifiant du slot de jeu**, celui que porte
`SessionSlot.slot_id` et que gardent `RunParticipant.gameSlots` comme les slots d'inscription. Il
existe avant la partie, survit aux relances, et vaut pour les deux surfaces.

### Un seul endroit calcule qui joue un slot (AC 8)

Les cinq lecteurs de `registration_id` recensés dans les Dev Notes ne changent pas chacun de leur
côté. Deux abstractions portent le changement :

- `DbalSlotPlayerSource` produit l'expression SQL « qui joue ce slot » que joignent les agrégats
  (`DbalPlayerStatsQuery`, `DbalLeaderboardQuery`), donc XP, niveau, classement et succès d'un coup ;
- `SlotsPlayedBy` répond à la même question côté autorisations, pour les trois gardes.

Le garde-fou du run privé (« la partie ne compte qu'une fois un objectif atteint dedans ») a été
déplacé du propriétaire vers **le joueur** : un co-joueur qui a fini son jeu franchit la porte tout
seul.

### Le classement « vitesse » suit aussi (AC 11)

L'AC ne nommait que les objectifs et les checks. Le troisième axe partait du même
`slot.registration_id` : le laisser en arrière aurait produit un co-joueur présent sur deux
classements et absent du troisième, sans raison lisible.

### Une réécriture de liste est un diff, pas un vider-recréer

Premier jet : supprimer les lignes du slot puis réinsérer la liste demandée. Un test l'a cassé -
Doctrine ordonne les INSERT avant les DELETE dans une même unité de travail, donc renvoyer une liste
inchangée insérait une ligne que la suppression n'avait pas encore retirée, et l'index unique
sautait. Le service compare donc l'existant au demandé et ne touche qu'aux différences, ce qui
préserve au passage la date d'ajout de ceux qui ne bougent pas.

### Ce qui n'a rien demandé (AC 15)

Les quotas de sélection de jeux comptent les `gameSlots` d'un participant. Un co-joueur n'en déclare
aucun, donc rien ne le compte : vérifié, pas modifié.

## Change Log

| Date | Version | Description | Auteur |
|---|---|---|---|
| 2026-08-25 | 0.1 | Rédaction de la story | Claude |
| 2026-08-25 | 0.2 | Portée runs privées, tous les pseudos sur un slot partagé | Claude |
| 2026-08-26 | 1.0 | Implémentation ; association sur le slot de jeu, axe vitesse inclus | Claude |
