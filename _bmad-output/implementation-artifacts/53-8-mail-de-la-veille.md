# Story 53.8 - Le mail de la veille

**Status:** review
**Epic:** 53 - Mon event, l'espace de l'inscrit (`_bmad-output/planning-artifacts/epics/epic-53-espace-inscrit.md`)
**Date:** 2026-11-11
**Branche:** `feature/epic-53-story-8-mail-de-la-veille` (depuis la 53.6, PR empilée)
**Dépend de:** 53.2 (checklist), 53.7 (liens).

## Story

En tant qu'inscrit,
je veux recevoir la veille un mail qui me rappelle l'heure, le lieu et ce qu'il me reste à faire,
afin d'arriver prêt.

## Contexte (cartographie du 2026-11-11)

- Aucun rappel avant l'événement aujourd'hui. Seule relance : confirmation d'inscription (story 4.23,
  `ProcessUnconfirmedRegistrationsMessage`, cron `20 * * * *`, `api/src/Schedule.php:81`), avec
  `Registration::reminderSentAt` pour ne pas renvoyer.
- Mails : classes PHP dans `api/src/Communications/Application/Email/` (base `ArchilanEmail`, envoi
  `Service/ArchilanMailer.php`), message + handler dans `Communications`.
- Modèle d'envoi planifié avec marque d'envoi et diffusion après commit : relances de la 4.23 et des sondages
  (`NotifySurveysMessage`, 51.3).

## Décisions

1. **Message planifié** `SendEventEveRemindersMessage`, cron `0 * * * *` sur le worker async.
2. **Qui** : inscriptions actives et **confirmées** d'un événement publié (`published` ou `in-progress`) dont
   `startsAt` est entre maintenant + 12 h et maintenant + 36 h, sans rappel déjà envoyé.
3. **Une seule fois** : `Registration::eveReminderSentAt` (nouveau champ nullable), posé dans la même transaction
   que la sélection ; le mail part après commit (best effort, comme la 4.23). Une inscription confirmée après le
   passage ne reçoit rien si l'événement commence dans moins de 12 h.
4. **Contenu** (`EventEveReminderEmail`) : « C'est demain ! », titre, date et heure de début (Europe/Paris), lieu,
   la checklist (lignes `todo` obligatoires seulement, via `RegistrantChecklistQuery` de 53.2 ; tout fait : « Tu es
   prêt »), bouton « Mon espace » vers `/evenements/<slug>/mon-event`. Pas les infos pratiques (elles peuvent
   contenir des codes : on ne les met pas dans un mail).
5. **Désactivable par événement** : `Event::eveReminderEnabled` (vrai par défaut), case dans le formulaire admin.
6. Pas de notification ni de push dans cette story.

## Critères d'acceptation

1. Un inscrit confirmé reçoit le mail une fois, dans la fenêtre prévue.
2. Un inscrit non confirmé, annulé, ou d'un événement brouillon ne reçoit rien.
3. Le mail liste seulement ce qui reste à faire, ou « Tu es prêt ».
4. Un événement avec le rappel désactivé n'envoie rien.
5. Un échec d'envoi n'annule pas la marque des autres inscriptions.

## Tests et gates

6. Unitaires : sélection (fenêtre, statuts, déjà envoyé), contenu du mail.
7. Fonctionnels : passage du message sur des données de test (horloge injectée), idempotence (deux passages = un
   mail).
8. `composer gates` vert ; `pnpm gates` vert (case admin).

## Tasks / Subtasks

- [x] **Task 1** (AC 2, 4, 6) - Champs `eveReminderSentAt` et `eveReminderEnabled`, migration.
- [x] **Task 2** (AC 1, 5, 7) - Message, handler, sélection, planification.
- [x] **Task 3** (AC 3) - `EventEveReminderEmail`.
- [x] **Task 4** (AC 4, 8) - Case admin, gates (envoi réel sur Mailpit non fait : Docker éteint).

## Dev Notes

- Pas de `date()` / `time()` : `ClockInterface`.
- Délivrabilité : voir `email_deliverability` en mémoire (envoi actuel via OVH) ; volume faible, pas de changement.

## Dev Agent Record

### Completion Notes

- Sélection : `SendEventEveRemindersHandler` (Registrations) prend les events `published` / `in-progress` au
  rappel actif dont le début tombe entre +12 h et +36 h, marque toutes les inscriptions concernées puis un seul
  flush, et n'envoie qu'ensuite, un message par inscrit, en best effort (compte supprimé ou échec : journalisé).
- « Ce qu'il reste à faire » : lignes obligatoires `todo` de `RegistrantChecklistQuery::forOwner`, traduites par
  `STEP_LABELS` ; si la checklist ne se lit pas, le mail part sans elle. Tout fait : « Tout est prêt de ton côté ».
- `Registration::reactivate()` efface la marque : une inscription annulée puis reprise reçoit à nouveau le mail.
- `eveReminderEnabled` : vrai par défaut ; un enregistrement admin sans le champ garde la valeur en base. La case
  est dans le bloc infos pratiques du formulaire d'événement.
- Le lien du mail utilise l'id de l'événement (`/evenements/<id>/mon-event`), comme les autres liens du hub.
- Tests fonctionnels (`SendEventEveRemindersTest`) lancés en CI seulement : Postgres local éteint.

### File List

- api/migrations/Version20261111100002.php (nouveau)
- api/src/Events/Domain/Entity/Event.php
- api/src/Events/Application/Service/AdminEventDrafts.php
- api/src/Events/Application/Query/AdminEventView.php
- api/src/Registrations/Domain/Entity/Registration.php
- api/src/Registrations/Application/Message/SendEventEveRemindersMessage.php (nouveau)
- api/src/Registrations/Application/Handler/SendEventEveRemindersHandler.php (nouveau)
- api/src/Communications/Application/Message/EventEveReminderMessage.php (nouveau)
- api/src/Communications/Application/Handler/EventEveReminderHandler.php (nouveau)
- api/src/Communications/Application/Email/EventEveReminderEmail.php (nouveau)
- api/src/Schedule.php
- api/config/packages/messenger.yaml
- api/tests/Functional/SendEventEveRemindersTest.php (nouveau)
- api/tests/Unit/Registrations/RegistrationEveReminderTest.php (nouveau)
- api/tests/Unit/Communications/HtmlEmailsTest.php
- frontend/src/features/admin/admin-event-form.tsx
- frontend/src/features/admin/admin-events-api.ts
- frontend/src/features/admin/admin-event-form-practical-info.test.tsx
