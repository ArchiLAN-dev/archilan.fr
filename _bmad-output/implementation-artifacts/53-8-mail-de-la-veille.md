# Story 53.8 - Le mail de la veille

**Status:** ready-for-dev
**Epic:** 53 - Mon event, l'espace de l'inscrit (`_bmad-output/planning-artifacts/epics/epic-53-espace-inscrit.md`)
**Date:** 2026-11-11
**Branche:** `feature/epic-53-story-8-mail-de-la-veille` (depuis `develop`)
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

- [ ] **Task 1** (AC 2, 4, 6) - Champs `eveReminderSentAt` et `eveReminderEnabled`, migration.
- [ ] **Task 2** (AC 1, 5, 7) - Message, handler, sélection, planification.
- [ ] **Task 3** (AC 3) - `EventEveReminderEmail`.
- [ ] **Task 4** (AC 4, 8) - Case admin, gates, envoi réel sur Mailpit.

## Dev Notes

- Pas de `date()` / `time()` : `ClockInterface`.
- Délivrabilité : voir `email_deliverability` en mémoire (envoi actuel via OVH) ; volume faible, pas de changement.

## Dev Agent Record
