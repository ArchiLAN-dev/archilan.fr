# Story 40.7: Les réglages de notification en tableau

**Status:** review
**Epic:** 40 - BK et push
**Date:** 2026-10-11

## Story

En tant que membre,
je veux régler chaque type de notification dans un tableau (cloche, push, Discord), un interrupteur par case,
afin de voir d'un coup d'oeil où chaque notification m'arrive et de le changer en un clic.

## Contexte

Retour de Jean (2026-10-11) sur « Ce qui me prévient » : les trois boutons « Cloche + push / Cloche seulement / Rien »
et la case « Aussi en message privé sur Discord » (40.6) disent la même chose deux fois et allongent la carte.

Ce qui est enregistré ne change pas : le canal (`bell_push`, `bell`, `none`, story 43.11b) et `discord` (40.6). Le
tableau en est une autre lecture : la cloche et le push donnent le canal, Discord la case.

## Critères d'acceptation

1. Un tableau : une ligne par type (libellé et explication), une colonne par canal (Cloche, Push, Discord), un
   interrupteur par case.
2. La cloche est la base : la couper met le type sur « rien » ; ses interrupteurs Push et Discord sont alors grisés.
   La rallumer le remet sur cloche seule.
3. Push : allumé = `bell_push`, éteint = `bell`. Discord : la préférence `discord` de la 40.6.
4. La colonne Discord n'apparaît que si le Discord du membre est relié (`discord` non nul).
5. Chaque interrupteur porte un nom lisible par un lecteur d'écran (« Partie lancée : push »).
6. Lisible sur téléphone sans défilement horizontal : le libellé passe à la ligne, les interrupteurs restent alignés.
7. Aucun changement d'API ni de données. Gates verts ; tests (ligne coupée et ses interrupteurs grisés, colonne
   Discord absente sans Discord relié, correspondance canal / interrupteurs).

## Tasks / Subtasks

- [x] **Task 1** (AC 1 à 6) - `NotificationPreferencesCard` en tableau, avec le `Switch` du site.
- [x] **Task 2** (AC 7) - Tests ; gates.

## Dev Agent Record

- `NotificationPreferencesCard` en tableau (`<table>`, une ligne par type, `Switch` du site) ; `channelAfter()` traduit les interrupteurs Cloche et Push en canal enregistré.
- Colonne Discord seulement si au moins un type a `discord` non nul (Discord relié).
- Aucun changement d'API ni de données.
- Gates front verts (1036 tests). Vérification visuelle à faire par Jean (page connectée, /compte/notifications).
