# Story 16.20: Seeds importées - suivi après une relance, suppression d'une partie terminée

**Status:** review
**Epic:** 16 - Parties privées
**Date:** 2026-09-26
**Origine :** retour d'un membre le 2026-09-26 - partie sur une seed générée hors du site, deux checks, pause du
serveur, puis plus rien dans le graphique ; et la partie, terminée, ne peut pas être supprimée.

## Story

En tant que membre qui importe une seed générée ailleurs,
je veux que ma partie reste suivie après une pause ou un redémarrage, et pouvoir la supprimer une fois terminée,
afin qu'une seed importée se comporte comme une seed du site.

## Contexte

Une seed importée n'a pas de slot observateur « Bridge ». Depuis la story 16.18, l'API transmet au lancement la
liste des slots (`SlotNames`) et le bridge se connecte en `TextOnly` sur le premier. L'orchestrateur ne stockait
pas cette liste avec les options du serveur (`serverOptionsJSON`) : une relance (reprise depuis la sauvegarde,
redémarrage après plantage) démarrait le bridge sans elle, qui demandait alors le slot « Bridge » inexistant et
était refusé par le serveur. Constaté sur la seed du membre : deux joueurs, pas de slot « Bridge ».

La suppression d'une partie terminée est refusée (`run_not_deletable`) pour protéger les stats. Or les stats de
profil sont calculées depuis les slots de la session, et la suppression d'une partie ne retire que la partie et
ses participants : les stats n'en dépendent pas.

## Critères d'acceptation

1. **Orchestrateur** : `SlotNames` est stocké avec les options du serveur et rejoué à chaque relance ; une seed du
   site, lancée sans liste, reste sans liste. Les sessions déjà lancées n'ont pas la liste en base : seuls les
   nouveaux lancements en profitent.
2. **API** : le propriétaire (ou un admin) supprime une partie terminée **sur une seed importée** ; une partie
   terminée générée sur le site reste protégée (`run_not_deletable`).
3. **Front** : l'onglet Réglages propose « Supprimer la partie » sur une partie en veille et sur une partie
   terminée à seed importée, selon la même règle que l'API.
4. `composer gates`, `pnpm gates`, `go test ./...` passent.

## Tasks / Subtasks

- [x] **Task 1** (AC 1) - Orchestrateur : liste des slots stockée et rejouée.
- [x] **Task 2** (AC 2) - API : garde de `PersonalRunDrafts::hardDelete`.
- [x] **Task 3** (AC 3) - Front : `showsSettingsDelete`.
- [x] **Task 4** (AC 4) - Gates.

## Dev Agent Record

### Déroulé TDD

| Étape | Rouge | Vert |
|---|---|---|
| `TestWithStoredServerOptions_replaysTheImportedSeedRoster` (orchestrateur) | liste vide à la relance | liste rejouée |
| `PersonalRunTest::testDeleteCompletedImportedSeedRunReturns204` | 422 `run_not_deletable` | 204, partie supprimée |
| `run-overview-visibility.test.ts` (`showsSettingsDelete`) | fonction absente | 20 verts |

La partie terminée générée sur le site garde son test existant (`testDeleteCompletedRunReturns422`).
