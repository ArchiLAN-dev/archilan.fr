# Story 51.5 - Questions à l'inscription

**Status:** review
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

- [x] **Task 1** (AC 2, 4, 5) - Port côté `Registrations`, contrôle dans `RegistrationSubmission`.
- [x] **Task 2** (AC 1, 3, 6) - Étape du stepper et récapitulatif.
- [x] **Task 3** (AC 7) - Gates verts, Dev Agent Record.

## Dev Agent Record

### Décisions prises pendant le dev

- Le contrôle vit dans `RegistrationSubmission`, derrière un port de `Registrations`
  (`PendingRegistrationSurveyQueryInterface`) implémenté côté `Surveys` (`DbalPendingRegistrationSurveyQuery`) :
  un sondage publié, ouvert, de public `registration`, avec au moins une question obligatoire et sans participation
  du membre bloque la validation (422 `registration_survey_incomplete`).
- Une inscription déjà confirmée n'est jamais rebloquée (resoumission après modification des jeux) ; l'inscription
  par un admin ne passe pas par `RegistrationSubmission`, donc n'est pas bloquée.
- `GET /events/{id}/my-surveys` expose désormais `audience` : le front filtre les sondages `registration` sans
  nouvel endpoint.
- Front : l'étape « Questions » n'apparaît que si un tel sondage existe ; la page `/inscription/{id}/questions`
  présente un sondage à la fois (le premier sans réponse) puis renvoie au récap. Le récap liste chaque sondage,
  ses réponses nominatives et un lien pour les modifier (pas de relecture pour un sondage anonyme).
- L'admin peut maintenant choisir le public « À l'inscription » à la création et dans les réglages.

### Fichiers

- API : `Registrations/Application/Query/PendingRegistrationSurveyQueryInterface.php`,
  `Surveys/Infrastructure/Dbal/DbalPendingRegistrationSurveyQuery.php`, `RegistrationSubmission.php`,
  `MemberSurveyQuery.php`, `config/services.yaml`, tests `RegistrationSurveyTest`, `MemberSurveyTest`.
- Front : `features/surveys/registration-questions.tsx` (+ test), page `inscription/[registrationId]/questions`,
  `registration-stepper.tsx`, `game-selection-gate.tsx`, `registration-recap-gate.tsx`,
  `survey-response-form.tsx` (lien de suite optionnel), `surveys-api.ts`, `admin-event-surveys.tsx`,
  `admin-survey-editor.tsx`.

### Gates

- API : phpstan, cs-fixer, DDD, rector OK ; ParaTest 3684 tests OK.
- Front : typecheck, lint, 1471 tests, build OK.
- Pas de vérification visuelle (serveurs de dev arrêtés).
