# Story 51.5 - Questions à l'inscription

**Status:** ready-for-dev
**Epic:** 51 - Sondages (`_bmad-output/planning-artifacts/epics/epic-51-sondages.md`)
**Date:** 2026-11-09
**Branche:** `feature/epic-51-story-5-questions-inscription` (depuis `develop`)
**Dépend de:** 51.2.

## Story

En tant que joueur qui s'inscrit à un événement,
je veux répondre aux questions de l'orga pendant mon inscription,
afin de tout faire en une fois et de ne pas être relancé ensuite.

## Contexte (cartographie du 2026-11-09)

- Parcours d'inscription : `frontend/src/app/(public)/evenements/[eventSlug]/inscription/`, étapes `page.tsx`
  (`RegistrationEligibilityGate`), `[registrationId]/jeux`, `[registrationId]/slots/[slotId]`,
  `[registrationId]/recap` (`RegistrationRecapGate`). Composants dans `frontend/src/features/events/`, dont
  `registration-stepper.tsx`.
- Validation de l'inscription : `POST /api/v1/registrations/{id}/submit`
  (`api/src/Registrations/Application/Command/RegistrationSubmission.php`).

## Décisions

1. **Étape « Questions »** dans le stepper, avant le récapitulatif, affichée seulement si l'événement a au moins un
   sondage publié et ouvert de public `registration`. Elle réutilise le formulaire de 51.2.
2. **Questions obligatoires bloquantes** : la validation de l'inscription est refusée tant qu'un sondage
   `registration` ouvert a une question obligatoire sans réponse (422 `registration_survey_incomplete`), par un
   port de lecture côté `Registrations` implémenté dans `Surveys`.
3. **Inscriptions d'avant la création du sondage** : elles ne sont pas bloquées ; le sondage leur apparaît par
   l'encart de 51.2 et les relances de 51.3.
4. Le récapitulatif reprend les réponses données, avec un lien pour les modifier (sondage nominatif).
5. Une inscription faite par un admin (`AdminRegistrationCreation`) n'est pas bloquée.

## Critères d'acceptation

1. Étape présente seulement s'il y a un sondage `registration` ouvert ; absente sinon (le stepper reste inchangé).
2. Validation refusée avec un message clair tant qu'une question obligatoire manque.
3. Réponses visibles et modifiables depuis le récapitulatif (nominatif).
4. Inscriptions existantes et inscriptions admin non bloquées.

## Tests et gates

5. Fonctionnels : refus de validation, validation après réponse, inscription admin non bloquée, absence de sondage.
6. Front : stepper avec et sans étape, récapitulatif.
7. `composer gates` et `pnpm gates` verts.

## Tasks / Subtasks

- [ ] **Task 1** (AC 2, 4, 5) - Port côté `Registrations`, contrôle dans `RegistrationSubmission`.
- [ ] **Task 2** (AC 1, 3, 6) - Étape du stepper et récapitulatif.
- [ ] **Task 3** (AC 7) - Gates verts, Dev Agent Record.
