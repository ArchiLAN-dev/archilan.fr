# Story 53.1 - Socle de l'espace inscrit

**Status:** in-progress (vérification réelle à faire)
**Epic:** 53 - Mon event, l'espace de l'inscrit (`_bmad-output/planning-artifacts/epics/epic-53-espace-inscrit.md`)
**Date:** 2026-11-11
**Branche:** `feature/epic-53-story-1-socle-espace-inscrit` (depuis `develop`)
**Dépend de:** rien. Prérequis de toutes les autres stories de l'epic.

## Story

En tant qu'inscrit à un événement,
je veux une page qui rassemble tout ce qui me concerne pour cet événement,
afin de ne pas chercher à six endroits quand je suis perdu.

## Contexte (cartographie du 2026-11-11)

- Inscription : `api/src/Registrations/Domain/Entity/Registration.php`, statuts `reserved` / `cancelled`,
  confirmée quand `submittedAt` n'est pas nul (`isAwaitingConfirmation()` l. 221).
- « Mon inscription » : `GET /api/v1/events/{eventId}/my-registration`
  (`RegistrationController.php:33`, `MyRegistrationQuery.php:28`) → `{registrationId, status, confirmed}`, 404 sans
  inscription active. Côté front : `fetchMyRegistrationCta(eventId)` (`frontend/src/features/events/events-api.ts:622`)
  renvoie `guest` / `registered` / `not_registered`.
- Événement : `api/src/Events/Domain/Entity/Event.php` (`startsAt`, `endsAt`, `venue` en une chaîne, statuts
  `draft`, `published`, `in-progress`, `completed`). Lecture publique : `getPublicEvent` (`features/events/public-events-api.ts`),
  horaires : `dayAndHour`, `eventSchedule` (`features/events/event-schedule`).
- SSR anonyme (story 32.5, `frontend_ssr_auth_cookie`) : tout ce qui dépend de l'inscrit se lit côté client.
- Modèle de page publique avec rendu client authentifié : `inscription/[registrationId]/recap/page.tsx` +
  `RegistrationRecapGate`.

## Décisions

1. **Route** `app/(public)/evenements/[eventSlug]/mon-event/page.tsx`. Titre de page « Mon event - <titre> »,
   `robots: noindex`. Absente du sitemap.
2. **Serveur** : lit l'événement public (`getPublicEvent`), `notFound()` s'il n'existe pas ou n'est pas public pour
   ce visiteur (mêmes règles que la page de l'événement, y compris l'event privé de la 50.7).
3. **Client** (`features/registrant-hub/registrant-hub-gate.tsx`) : `fetchMyRegistrationCta(eventId)`.
   - `guest` → redirection vers la connexion avec retour sur l'espace ;
   - `not_registered` → redirection vers la page de l'événement ;
   - `registered` → l'espace, avec `registrationId` et `confirmed` passés aux sections.
4. **En-tête** : titre, dates (`eventSchedule`), lieu (`venue`), statut de l'inscription (« Inscription confirmée »
   ou « Inscription à confirmer » avec un lien vers le récapitulatif), lien « Page de l'événement ».
5. **Moment** : fonction pure `eventPhase(event, now)` dans `features/registrant-hub/event-phase.ts` →
   `before` | `during` | `after` (règles de l'epic). Affichée en pastille dans l'en-tête (« J-3 », « En cours »,
   « Terminé »). Le même calcul existe côté API (53.2, 53.8) : un test de chaque côté sur les mêmes cas limites.
6. **Sections** : composant `RegistrantHubSection` (titre, icône, contenu) et l'emplacement des sections des
   stories suivantes (checklist, infos pratiques, outils, guides). Une section sans contenu ne s'affiche pas.
7. **Mobile d'abord** : une colonne, cartes pleine largeur ; deux colonnes à partir de `lg`.

## Critères d'acceptation

1. Un inscrit (confirmé ou non) voit `/evenements/<slug>/mon-event` : en-tête, pastille du moment.
2. Un visiteur est renvoyé vers la connexion puis revient sur l'espace après connexion.
3. Un membre non inscrit, ou dont l'inscription est annulée, est renvoyé vers la page de l'événement.
4. Événement inconnu ou non visible → 404.
5. La page n'est pas indexée et n'apparaît pas dans le sitemap.

## Tests et gates

6. Unitaires : `eventPhase` (avant, pile au début, pendant, statut `in-progress` avant `startsAt`, après `endsAt`,
   statut `completed` avant `endsAt`), garde d'accès (trois cas), rendu de l'en-tête.
7. `pnpm gates` vert. Pas de changement API.
8. Vérification réelle dans Chrome avec un inscrit, un non-inscrit et un visiteur.

## Tasks / Subtasks

- [x] **Task 1** (AC 4, 5) - Route, lecture serveur, metadata noindex, exclusion du sitemap.
- [x] **Task 2** (AC 1-3) - Garde client, en-tête, `eventPhase`, `RegistrantHubSection`.
- [ ] **Task 3** (AC 6-8) - Tests, gates, vérification réelle. Tests et gates faits ; vérification réelle à faire.

## Dev Notes

- Ne pas encore toucher à la page publique ni au menu : c'est la 53.7.
- Tests DOM via `jsdom-environment.mjs` (cf. 3.17).

## Dev Agent Record

### Agent Model Used

Claude Opus 5.5

### Completion Notes List

- Route `app/(public)/evenements/[eventSlug]/mon-event/page.tsx` : lecture serveur de l'événement public
  (`React.cache`, ISR 300 s comme la page de l'événement), `notFound()` sans événement, noindex. Absente du sitemap
  (qui ne liste que `/evenements/<id>`), ajoutée au `disallow` de `robots.ts` comme le parcours d'inscription.
- Accès : `fetchRegistrantHubAccess` (`features/registrant-hub/registrant-hub-api.ts`), distinct de
  `fetchMyRegistrationCta` : une panne réseau donne `error` (carte d'erreur), jamais « non inscrit », pour ne pas
  renvoyer un inscrit hors de son espace sur un réseau de LAN capricieux. `hubRedirect` : visiteur → connexion avec
  `returnTo`, non inscrit ou inscription annulée → page de l'événement.
- `eventPhase` / `eventPhaseLabel` (`event-phase.ts`) : statut admin prioritaire, sinon dates ; pastille « J-n »,
  « Demain », « Aujourd'hui » comptés en jours calendaires de Paris, « En cours », « Terminé ».
- Horloge : `useMinuteClock` (`useSyncExternalStore`, une relecture par minute, nulle côté serveur) : pas de
  `Date.now()` pendant le rendu (AC-HK3), prête pour la 53.6.
- `RegistrantHubView` : en-tête (titre, pastille, horaires, lieu, statut de l'inscription avec bouton « Confirmer
  mon inscription » si besoin) et une première section « Mon inscription » (récapitulatif, ma partie si confirmée).
  `RegistrantHubSection` ne rend rien sans contenu.
- Gates front : typecheck, lint, test (250 suites, 1584 tests), build verts.
- Vérification réelle non faite : Docker Desktop arrêté sur le poste au moment du dev (ni API ni base).

### File List

- `frontend/src/app/(public)/evenements/[eventSlug]/mon-event/page.tsx` (nouveau)
- `frontend/src/features/registrant-hub/event-phase.ts` (nouveau)
- `frontend/src/features/registrant-hub/event-phase.test.ts` (nouveau)
- `frontend/src/features/registrant-hub/registrant-hub-api.ts` (nouveau)
- `frontend/src/features/registrant-hub/registrant-hub-api.test.ts` (nouveau)
- `frontend/src/features/registrant-hub/registrant-hub.tsx` (nouveau)
- `frontend/src/features/registrant-hub/registrant-hub.test.tsx` (nouveau)
- `frontend/src/features/registrant-hub/use-minute-clock.ts` (nouveau)
- `frontend/src/app/robots.ts` (modifié)
- `frontend/src/app/robots.test.ts` (modifié)
