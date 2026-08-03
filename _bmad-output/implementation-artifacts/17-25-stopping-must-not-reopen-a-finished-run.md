# Story 17.25 - L'arrêt du conteneur ne doit pas rouvrir une partie terminée

## Status

Done

## Story

**En tant que** propriétaire d'une partie que je viens de terminer,
**je veux** qu'elle reste terminée,
**afin de** voir son récap et ne pas la retrouver relançable comme si de rien n'était.

## Contexte

Deux parties de suite ont présenté la même signature : le propriétaire clique sur « terminer », la
session passe bien en `finished`, le récap est construit... et la partie se retrouve en `idle`. Le
récap ne s'affiche pas, sa carte n'étant rendue que sur une partie `completed`.

Reconstitution par horodatages, identique sur les deux cas :

| Écart | Événement |
|---|---|
| T+0 s | fin de partie : session `finished`, `Run::complete()` → run **`completed`** |
| T+23 s | l'orchestrateur arrête le conteneur et émet le webhook `session.stopped` |
| T+25 s | le récap est construit |

À T+23 s, `session.stopped` appelle `markPersonalRunStopped()`, donc `Run::markStopped()`, qui
écrivait `idle` **sans aucune garde**. L'arrêt du conteneur - conséquence normale de la fin de
partie - venait donc annuler la complétion vingt secondes plus tard.

L'entité possédait déjà `isTerminal()`, qui reconnaît `completed` et `cancelled` ; `markStopped()`
ne la consultait simplement pas.

## Acceptance Criteria

**AC1 - Un arrêt de runner ne rétrograde jamais une partie terminale.** `markStopped()` est sans
effet sur une partie `completed` ou `cancelled`, et continue de passer en `idle` toute partie
non terminale.

**AC2 - Une session terminée complète la partie.** La réconciliation des parties bloquées mappe
désormais une session `finished` vers `completed`, et non plus vers `idle` comme les statuts
`stopped` / `crashed` / `failed` avec lesquels elle était confondue.

**AC3 - Les parties déjà corrompues sont réparables.** Une commande complète les parties dont la
session est terminée mais qui sont restées non terminales, avec un mode `--dry-run`.

**AC4 - Quality gates.** `composer gates` vert.

## Tasks / Subtasks

- [x] Task 1: garde `isTerminal()` dans `Run::markStopped()` (AC1).
- [x] Task 2: `Run::markSessionFinished()` - complète une partie non terminale (AC2).
- [x] Task 3: `ReconcileStuckRunsHandler` distingue `finished` des autres statuts résolus (AC2).
- [x] Task 4: commande `app:runs:repair-finished` (AC3).
- [x] Task 5: tests - arrêt sur partie terminée, sur partie en cours, réconciliation (AC1, AC2).
- [x] Task 6: gates (AC4).

## Dev Notes

**La garde va dans l'entité, pas dans le webhook.** `Run::markStopped()` a huit appelants
(webhooks, réconciliation, orchestrateur, cycle de vie). Corriger le seul chemin observé aurait
laissé les sept autres capables de reproduire le défaut. L'invariant - « un arrêt ne rouvre pas ce
qui est fini » - appartient au domaine.

**Ce n'était pas le réconciliateur**, contrairement à ma première hypothèse : il n'intervient
qu'après cinq minutes dans un état transitoire, et il ne s'était écoulé que 23 secondes. Il portait
néanmoins le **même défaut sous une autre forme** - `Session::STATUS_FINISHED` était rangé parmi les
statuts « résolus » et mappé vers `idle` comme un crash. D'où AC2, corrigé dans la même passe.

**Conséquences au-delà du récap manquant.** `idle` fait partie des statuts relançables
(`startableStatuses = [draft, idle]`) : une partie terminée pouvait être redémarrée. Et
`isTerminal()` renvoyant `false`, elle restait modifiable - invitations, overrides de configuration.
Le récap invisible n'était que le symptôme le plus visible.

**Réparation.** Deux parties étaient concernées en local, dont une datant de la veille. Une
projection ou un statut faux ne se répare jamais tout seul : la commande est le véhicule, comme
`app:sessions:rebuild-recap` l'était pour les récaps vides de la story 9.48. À passer en production
après déploiement.
