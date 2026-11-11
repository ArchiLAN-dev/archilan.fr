# Story 53.2 - Ce qu'il me reste à faire

**Status:** ready-for-dev
**Epic:** 53 - Mon event, l'espace de l'inscrit (`_bmad-output/planning-artifacts/epics/epic-53-espace-inscrit.md`)
**Date:** 2026-11-11
**Branche:** `feature/epic-53-story-2-checklist-inscrit` (depuis `develop`)
**Dépend de:** 53.1.

## Story

En tant qu'inscrit,
je veux voir ce qu'il me reste à faire avant l'événement, coché quand c'est fait,
afin de ne rien oublier sans fouiller le site.

## Contexte (cartographie du 2026-11-11)

- `Registration::gameSlots` : JSON `list<{slotId, gameId, slotOrder, apworldHash?, playerYaml?, needsReview?}>`.
  Un YAML est fait quand `playerYaml` n'est pas nul. `Event::gameSelectionEnabled` dit si l'événement a un choix
  de jeux.
- Questions d'inscription en attente : `Registrations/Application/Query/PendingRegistrationSurveyQueryInterface`
  (`hasUnansweredRequired`, implémentée par `Surveys`). Sondages en attente côté joueur : `GET /events/{id}/my-surveys`.
- Présence : `RegistrationPresenceWindow`, facultative (sans plage = présent tout l'événement, story 52.2).
- Discord : `discordUsername` du profil (`GET /api/v1/account/discord/link`).
- Paiement : rien sur l'inscription ; `Payments\Application\Query\HelloAssoPaymentLookup::findForEventAndEmail`
  (admin seulement aujourd'hui) → `{status, amountCents, syncedAt, isStale}|null`. N'a de sens que si l'événement a
  un `helloassoFormSlug`.
- Modèle d'interface de lecture inter-contextes : `PendingRegistrationSurveyQueryInterface`.

## Décisions

1. **Endpoint** `GET /api/v1/registrations/{id}/checklist`, propriétaire de l'inscription uniquement (403 sinon,
   404 inconnue ou annulée). Requête `RegistrantChecklistQuery` dans `Registrations/Application/Query`.
2. **Réponse** `{data: {phase, items: [{key, state, required, detail?}]}}`, `state` ∈ `done` | `todo` | `optional`.
   Le front construit le libellé et le lien à partir de `key` (pas d'URL en dur dans l'API).
3. **Lignes**, dans cet ordre, chacune présente seulement si elle s'applique :
   - `confirm` (obligatoire) : `submittedAt` non nul.
   - `games` (obligatoire si `gameSelectionEnabled`) : au moins un slot.
   - `yaml` (obligatoire si au moins un slot) : tous les slots ont un `playerYaml` ; `detail` = `{done, total}`.
   - `registration_questions` (obligatoire) : présente seulement s'il y a un sondage de public `registration` ;
     faite si `hasUnansweredRequired` est faux.
   - `surveys` (facultative) : sondages ouverts non répondus de l'inscrit (`detail` = nombre). Interface
     `PendingSurveysForRegistrantQueryInterface`, implémentée par `Surveys`.
   - `payment` (obligatoire) : seulement si l'événement a un `helloassoFormSlug` ; faite si un paiement HelloAsso
     validé existe pour l'email du membre. Interface `RegistrantPaymentQueryInterface`, implémentée par `Payments`
     à partir de `HelloAssoPaymentLookup`. Synchro périmée (`isStale`) et rien trouvé → `todo` avec
     `detail.stale = true` (le front dit « pas encore vu, la synchro peut prendre une heure »).
   - `presence` (facultative) : `done` si au moins une plage, sinon `optional` (« Dis-nous quand tu es là »).
   - `discord` (facultative) : compte Discord relié.
4. **Moment** `phase` calculé côté API par un service de domaine pur `EventPhase` (dans `Events/Domain/Service`),
   mêmes règles que `eventPhase` front (53.1). La requête renvoie `phase` pour que 53.6 et 53.8 l'utilisent.
5. **Front** : section « Ce qu'il me reste à faire » dans l'espace. Compteur « 3 sur 5 », lignes cochées, chaque
   ligne `todo` est un lien : `confirm` → récapitulatif, `games` → `inscription/[id]/jeux`, `yaml` → premier slot
   sans YAML, `registration_questions` → `inscription/[id]/questions`, `surveys` → le premier sondage, `payment`
   → formulaire HelloAsso de l'événement, `presence` → ancre de la carte présence (53.3) ou récapitulatif,
   `discord` → `/compte/securite`. Tout fait : « Tu es prêt ! » et la liste se replie.

## Critères d'acceptation

1. L'inscrit voit la checklist de son inscription, avec seulement les lignes qui s'appliquent.
2. Chaque ligne passe à `done` dès que l'action est faite (relecture au retour sur la page).
3. Un autre membre reçoit 403, une inscription annulée 404, un visiteur 401.
4. Le paiement n'apparaît que pour un événement relié à HelloAsso.
5. Les liens mènent au bon écran.

## Tests et gates

6. Unitaires : construction des lignes (chaque condition d'apparition, chaque état), `EventPhase`.
7. Fonctionnels : endpoint (inscrit confirmé sans jeux, YAML partiels, sondage d'inscription, paiement trouvé ou
   non), 401 / 403 / 404.
8. Front : rendu des lignes, liens, état « Tu es prêt ».
9. `composer gates` et `pnpm gates` verts (`app:architecture:ddd` : interfaces dans `Registrations`, implémentations
   dans `Surveys` et `Payments`).

## Tasks / Subtasks

- [ ] **Task 1** (AC 6) - `EventPhase`, construction pure des lignes, tests unitaires.
- [ ] **Task 2** (AC 1, 4) - Interfaces de lecture et implémentations (`Surveys`, `Payments`), `RegistrantChecklistQuery`.
- [ ] **Task 3** (AC 3, 7) - Contrôleur et tests fonctionnels.
- [ ] **Task 4** (AC 2, 5, 8) - Section front, client API, tests.
- [ ] **Task 5** (AC 9) - Gates, vérification réelle.

## Dev Notes

- Un appel Application par action de contrôleur ; pas de `Connection` dans l'Application.
- Le paiement HelloAsso se rattache par email : vérifier que l'email utilisé est celui du compte et le dire dans
  le libellé si rien n'est trouvé (« payé avec un autre email ? préviens l'orga »).

## Dev Agent Record
