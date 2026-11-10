# Story 11.9 - Choisir les slots d'une session d'événement

**Status:** review
**Epic:** 11 - Session Management UX/UI Overhaul (`_bmad-output/planning-artifacts/epics/epic-11-session-management-uxui-overhaul.md`)
**Date:** 2026-11-10
**Branche:** `feature/epic-11-story-9-selection-des-slots` (depuis `develop`)
**Dépend de:** story 11.4 (assistant de création en ligne). Front seul.

## Story

En tant qu'admin d'un événement,
je veux choisir les slots qui partent dans une nouvelle session,
afin de générer une seed avec une partie des joueurs seulement (vague, groupe, rattrapage) sans toucher aux
inscriptions.

## Contexte

Retour de Jean le 2026-11-10 sur `/admin/evenements/{id}/session`. L'écran « Créer une session » prend tous les slots
des inscriptions confirmées. L'API (`POST /admin/events/{id}/sessions`) accepte déjà n'importe quelle liste de slots :
rien à changer côté serveur. La sauvegarde des noms reste celle d'aujourd'hui (« Créer la session » ne génère rien).

## Décisions

1. Une case par slot, cochée par défaut ; une case « tous » dans l'en-tête (état mixte quand une partie est cochée).
2. Un compteur « n slots sur m » à côté des boutons de création ; création impossible sans slot coché.
3. Seuls les slots cochés sont validés (nom requis, 16 caractères, doublons entre slots cochés) et envoyés.
4. Un slot décoché garde son nom et reste éditable, sa ligne est atténuée. « Régénérer les noms » garde la sélection.

## Critères d'acceptation

1. À l'ouverture, tous les slots sont cochés : le comportement d'avant est inchangé.
2. Décocher un slot le retire de la requête de création et de la validation.
3. La case de l'en-tête coche ou décoche tout, et reflète l'état mixte.
4. Aucun slot coché : les deux boutons de création sont désactivés.
5. Tests unitaires de la validation et de la charge envoyée ; gates front verts.

## Tâches

- [x] Sélection dans l'état de l'assistant, cases et compteur.
- [x] Validation et création limitées aux slots cochés.
- [x] Tests, gates.

## Dev Agent Record

- `features/admin/admin-session-wizard.ts` (nouveau) : type `WizardSlot` avec `selected`, génération des noms
  (déplacée telle quelle depuis la page), `regenerateSlotNames` (garde la sélection), `validateSlots` (slots cochés
  seulement), `canCreateSession`, `creationPayload`, `selectionState`, `setSlotSelected`, `setAllSelected`.
- `admin-session-page.tsx` : case par ligne, case d'en-tête (état mixte via `indeterminate`), ligne atténuée si
  décochée, compteur « n slots sur m ».
- Suite : story 11.10 (co-joueurs d'un slot d'événement), demandée par Jean le même jour.
- Tests : `admin-session-wizard.test.ts` (5). typecheck, lint, test (1550), build verts.
