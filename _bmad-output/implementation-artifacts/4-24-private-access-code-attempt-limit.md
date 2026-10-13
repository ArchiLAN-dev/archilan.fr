# Story 4.24: Limite de tentatives sur le code d'accès d'un événement privé

**Status:** review
**Epic:** 4 - Inscription aux événements
**Date:** 2026-10-13

## Story

En tant qu'organisateur d'un événement privé ouvert par code (ArchiLAN 4),
je veux qu'un membre ne puisse pas essayer des codes à la chaîne,
afin que le code reste le seul moyen pour un non-adhérent d'entrer.

## Contexte

Relevé pendant la story 4.23 : `POST /api/v1/events/{id}/verify-private-access` vérifie le mot de passe à chaque appel,
sans limite. Chaque tentative est déjà journalisée dans `event_private_access_log` (membre, événement, accordé ou non,
date ; conservé 365 jours). Aucune brique de limitation n'est installée dans l'API : on s'appuie sur ce journal plutôt
que d'ajouter une dépendance.

## Critères d'acceptation

1. **Limite** : après 10 tentatives refusées d'un même membre sur un même événement dans l'heure glissante, une nouvelle
   tentative est refusée sans vérifier le mot de passe : réponse 429, code `too_many_attempts`, message lisible. Elle
   n'est pas journalisée (le journal ne grossit pas pendant le blocage).
2. **Fin du blocage** : dès que la plus ancienne des 10 tentatives a plus d'une heure, le membre peut réessayer.
3. **Sans effet sur les autres** : un autre membre, ou le même membre sur un autre événement, n'est pas bloqué. Un code
   accepté ne compte pas dans la limite.
4. **Front** : le formulaire « J'ai un code d'accès » affiche « Trop d'essais. Réessaie dans une heure. » sur une 429,
   distinct de « Code d'accès invalide ».
5. Tests (blocage à la 11e tentative, pas de vérification ni de journal pendant le blocage, reprise après une heure,
   isolement par membre et par événement, message front) et gates verts.

## Tasks / Subtasks

- [x] **Task 1** (AC 1-3) - `EventPrivateAccessLogRepositoryInterface::countDeniedSince(eventId, userId, since)` ;
  `VerifyPrivateEventAccess` renvoie `PrivateAccessResult` avec `limited: true`, que le contrôleur traduit en 429 (même
  modèle que les autres limites de l'API) ; tests fonctionnels.
- [x] **Task 2** (AC 4) - Front : message sur 429 dans `PrivateAccessDisclosure` ; test.
- [x] **Task 3** (AC 5) - Gates.

## Dev Agent Record

- API : `countDeniedSince` sur le journal des accès privés ; `VerifyPrivateEventAccess::MAX_DENIED_PER_HOUR = 10`,
  vérifié avant tout contrôle du mot de passe et sans journaliser ; `PrivateAccessResult::$limited` traduit en 429
  `too_many_attempts` par le contrôleur, qui ne renvoie plus que `granted`.
- Front : état `limited` du formulaire de code, « Trop d'essais. Réessaie dans une heure. » (`codeCheckFailure`).
- Tests : `VerifyPrivateEventAccessTest` (blocage, pas de journal, autre membre et autre événement libres, reprise après
  une heure), `registration-fixes.test.ts`.
