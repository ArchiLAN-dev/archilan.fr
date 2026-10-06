# Story 41.25: Quêtes d'accueil

**Status:** review
**Epic:** 41 - Les Pelles, monnaie virtuelle d'ArchiLAN
**Date:** 2026-10-06

## Story

En tant que nouveau membre,
je veux une liste de premiers pas récompensés en pelles,
afin de découvrir le site en jouant plutôt qu'en lisant.

## Contexte

Retenue par Jean (2026-10-06) : « Quêtes d'accueil à part », hors du tirage des quêtes de la semaine. Décisions prises
par Claude et annoncées à Jean :

- les étapes sont **définies dans le code** (comme les métriques des quêtes), payées une fois chacune, à vie ;
- toutes sauf Discord se lisent dans ce qui a été joué (checks, goals, hebdos) : un second compte ne gagne rien sans
  jouer, et un compte Discord ne se lie qu'à un compte ;
- elles concernent les comptes créés **depuis leur mise en service** (réglage `welcome_quests_since`, posé par la
  migration) : les anciens membres ne touchent pas d'arriéré.

## Critères d'acceptation

1. **Étapes** (code) : lier son compte Discord (10), faire son premier check (10), jouer sa première hebdo (15),
   jouer une partie avec un autre membre (un check chacun dans la même partie, 15), atteindre son premier goal (25).
2. **Paiement** : le passage toutes les 5 minutes (celui des quêtes de la semaine) paie chaque étape faite, une fois
   (clé `welcome:{étape}:{membre}`, motif `welcome_reward`), avec une notification ; un compte banni ou effacé ne
   gagne rien.
3. **Éligibilité** : un compte créé avant la mise en service n'a ni bloc ni paiement.
4. **Portefeuille** : un bloc « Premiers pas » au-dessus des quêtes de la semaine liste les étapes (faite, créditée
   bientôt, à faire avec un lien vers où la faire) et le total ; il disparaît une fois tout payé.
5. Gates verts ; tests (chaque étape, paiement unique, éligibilité, bloc).

## Tasks / Subtasks

- [x] **Task 1** (AC 1-3) - `WelcomeStep`, requête des étapes faites et non payées, `AwardWelcomeQuests` branché sur
  le passage des quêtes, `MyWelcomeQuests` + `GET /api/v1/me/welcome-quests`, migration ; tests.
- [x] **Task 2** (AC 4) - Front : bloc « Premiers pas » du portefeuille, libellé du motif ; tests.
- [x] **Task 3** (AC 5) - Gates, vérification dans l'app.

## Dev Agent Record

- API : `WelcomeStep` (enum : libellé, description, récompense, clé `welcome:{étape}:{membre}`) ;
  `WelcomeQuestsQueryInterface` / `DbalWelcomeQuestsQuery` (étapes faites, non payées, depuis le compte, le fil des
  parties, les goals des slots et les tentatives hebdo) ; `AwardWelcomeQuests`, appelé par
  `AwardWeeklyQuestsMessageHandler` dans le même passage de 5 minutes ; `MyWelcomeQuests` +
  `GET /api/v1/me/welcome-quests` (`{welcome: null}` pour un compte plus ancien ou tout payé) ;
  `PelleReason::WelcomeReward` ; réglage `welcome_quests_since` posé par la migration `Version20261006180000`.
- Front : `welcome-quests.tsx` (bloc « Premiers pas » au-dessus des quêtes de la semaine, lien vers où faire chaque
  étape), libellé du motif dans l'historique.
- `composer gates` (2 791) et `pnpm gates` (850) verts.
