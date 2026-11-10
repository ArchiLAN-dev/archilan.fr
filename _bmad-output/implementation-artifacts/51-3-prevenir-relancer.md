# Story 51.3 - Prévenir et relancer (mail, notification, push)

**Status:** review
**Epic:** 51 - Sondages (`_bmad-output/planning-artifacts/epics/epic-51-sondages.md`)
**Date:** 2026-11-09
**Branche:** `feature/epic-51-story-3-prevenir-relancer` (depuis la branche de 51.4, PR empilée)
**Dépend de:** 51.2. Indépendante de 51.4.

## Story

En tant qu'orga,
je veux que le public d'un sondage soit prévenu à son ouverture et que ceux qui n'ont pas répondu soient relancés
avant la fermeture,
afin d'obtenir des réponses sans courir après chacun.

## Contexte (cartographie du 2026-11-09)

- Mail asynchrone : message + handler + classe de mail dans `api/src/Communications/Application/`, envoi par
  `ArchilanMailer`. Modèle : `RegistrationConfirmationReminderMessage` / `...Handler` / `...Email`.
- Envoi planifié : `api/src/Schedule.php` (Symfony Scheduler, `RecurringMessage::cron`), modèle
  `ProcessUnconfirmedRegistrationsMessage` (`20 * * * *`) et son handler
  `api/src/Registrations/Application/Handler/ProcessUnconfirmedRegistrationsHandler.php` : sélection des
  candidats, marquage, puis dispatch best effort (utilisateur supprimé ou sans mail ignoré, échec journalisé).
- Notifications : port `api/src/Community/Application/Support/Notifier.php`, implémenté par
  `NotificationService` (préférences, cloche, temps réel, push, Discord). Nouveau type : `CONFIGURABLE` de
  `NotificationPreferenceService`, `PUSHABLE_TYPES` et `forNotification` de `PushMessageFactory`, rendu front dans
  `frontend/src/features/community/notification-center.tsx` et `notification-content.ts`.
- Délivrabilité : les mails de prod partent encore en `@dyosis.com` via OVH. Volume faible ici (un mail par
  sondage, une relance), mais à garder en tête.

## Décisions

1. **Ouverture** : un message planifié toutes les 10 minutes cherche les sondages publiés dont `opensAt` est
   passé et `openingNotifiedAt` est nul, pose `openingNotifiedAt` (idempotent), puis, après commit :
   - notification `survey_opened` (lien vers la page de réponse) à chaque membre du public ;
   - mail `SurveyOpenedEmail` si `sendEmail` est vrai.
   Un sondage de public `registration` ne prévient que les inscrits existants qui n'ont pas répondu.
2. **Relance automatique** : unique, 48 h avant `closesAt` (ou à mi-période si la période fait moins de 4 jours),
   aux seuls non-répondants, mail `SurveyReminderEmail` + notification `survey_reminder`. Marquage
   `reminderSentAt` sur le sondage.
3. **Relance manuelle** : `POST /api/v1/admin/surveys/{id}/remind` → aux non-répondants ; au plus une fois par
   24 h (429 `survey_remind_too_soon` sinon) ; refusé hors période (409 `survey_closed`).
4. **Préférences** : `survey_opened` et `survey_reminder` sont configurables (cloche + appareils par défaut). Le
   mail suit `sendEmail` du sondage, pas les préférences de notification (c'est un mail lié à l'inscription).
5. Les éligibles sont calculés par le port de 51.2, au moment de l'envoi.

## Critères d'acceptation

1. Un sondage publié avec une ouverture passée déclenche une seule fois la notification et le mail, même si le
   planificateur repasse ou rattrape un passage manqué.
2. Un sondage publié après son `opensAt` est annoncé au passage suivant du planificateur.
3. La relance automatique ne touche que les non-répondants, une seule fois.
4. La relance manuelle respecte le délai de 24 h et la période.
5. `sendEmail` faux : notifications seulement.
6. Mails en français, avec le titre du sondage, la date de fermeture et un bouton « Répondre » vers la page de
   réponse ; mention de l'anonymat si le sondage est anonyme.
7. Bouton « Relancer les non-répondants » sur l'écran admin du sondage (avec l'écran de 51.4 ; s'il n'existe pas
   encore, livrer l'API et le brancher en 51.4).

## Tests et gates

8. Fonctionnels : idempotence de l'ouverture, ciblage de la relance, délai de la relance manuelle, `sendEmail`
   faux, utilisateurs supprimés ou sans mail ignorés.
9. Front : rendu des deux types de notification.
10. `composer gates` et `pnpm gates` verts.

## Tasks / Subtasks

- [x] **Task 1** (AC 1, 2, 5, 8) - Message planifié d'ouverture, marquage, notification et mail.
- [x] **Task 2** (AC 3, 8) - Relance automatique.
- [x] **Task 3** (AC 4, 7, 8) - Relance manuelle (API, bouton).
- [x] **Task 4** (AC 6, 9) - Classes de mail, types de notification (préférences, push, front).
- [x] **Task 5** (AC 10) - Gates verts, Dev Agent Record.

## Dev Agent Record

### Décisions prises pendant le dev

- L'annonce d'ouverture va aux membres du public **qui n'ont pas encore répondu**, pour tous les publics (pas
  seulement `registration`) : prévenir quelqu'un qui a déjà répondu n'apporte rien.
- Une annonce faite après l'heure de relance vaut relance : la relance automatique ne la suit pas de dix minutes.
- Deux relances, automatique comprise, sont espacées d'au moins 24 h : une relance manuelle récente remplace la
  relance automatique (marquée faite sans envoi).
- Une relance manuelle vaut annonce d'ouverture (marquée faite), pour ne pas envoyer les deux.
- Destinataires : comptes non supprimés ; un compte sans adresse reçoit la notification, pas le mail.
- Le lien de réponse est `/evenements/{eventId}/sondages/{surveyId}` (un événement n'a pas de slug, son id en tient
  lieu).
- Un seul message asynchrone (`SurveyNoticeMessage`, drapeau `reminder`) et un seul handler choisissent entre
  `SurveyOpenedEmail` et `SurveyReminderEmail`.
- Les deux types sont configurables, cloche + appareils par défaut, sans Discord par défaut (activable par le
  membre).
- Écran admin : bouton « Relancer les non-répondants » sous la liste des non-répondants (sondage publié, au moins un
  non-répondant), avec la date de la dernière relance. La période est vérifiée par l'API (409), pas par l'écran.

### Routes

- `POST /api/v1/admin/surveys/{id}/remind` : 200 `{recipients, lastRemindedAt}`, 429 `survey_remind_too_soon`
  (avec `lastRemindedAt`), 409 `survey_closed`.
- `GET /api/v1/admin/surveys/{id}` expose `openingNotifiedAt`, `reminderSentAt`, `lastRemindedAt`.

### File List

- `api/migrations/Version20261109120003.php` (nouveau)
- `api/src/Surveys/Domain/Entity/Survey.php`
- `api/src/Surveys/Domain/Repository/SurveyRepositoryInterface.php`
- `api/src/Surveys/Infrastructure/Doctrine/DoctrineSurveyRepository.php`
- `api/src/Surveys/Application/Message/NotifySurveysMessage.php` (nouveau)
- `api/src/Surveys/Application/Handler/NotifySurveysHandler.php` (nouveau)
- `api/src/Surveys/Application/Service/SurveyAnnouncer.php` (nouveau)
- `api/src/Surveys/Application/Command/RemindSurveyNonRespondents.php` (nouveau)
- `api/src/Surveys/Application/Command/SurveyReminderResult.php` (nouveau)
- `api/src/Surveys/Application/Command/SurveyReminderOutcome.php` (nouveau)
- `api/src/Surveys/Application/Support/SurveyEventPeriod.php`
- `api/src/Surveys/Application/Query/SurveyAudienceQueryInterface.php`
- `api/src/Surveys/Application/Query/AdminSurveyQueryInterface.php`
- `api/src/Surveys/Infrastructure/Adapter/RepositorySurveyEvent.php`
- `api/src/Surveys/Infrastructure/Dbal/DbalSurveyAudienceQuery.php`
- `api/src/Surveys/Infrastructure/Dbal/DbalAdminSurveyQuery.php`
- `api/src/Surveys/Presentation/Controller/AdminSurveyController.php`
- `api/src/Communications/Application/Message/SurveyNoticeMessage.php` (nouveau)
- `api/src/Communications/Application/Handler/SurveyNoticeHandler.php` (nouveau)
- `api/src/Communications/Application/Email/SurveyOpenedEmail.php` (nouveau)
- `api/src/Communications/Application/Email/SurveyReminderEmail.php` (nouveau)
- `api/src/Community/Application/Service/NotificationPreferenceService.php`
- `api/src/Community/Application/Support/PushMessageFactory.php`
- `api/src/Schedule.php`
- `api/config/packages/messenger.yaml`
- `api/tests/Functional/SurveyNoticesTest.php` (nouveau)
- `api/tests/Functional/FriendActivityTest.php`
- `api/tests/Unit/Surveys/SurveyTest.php`
- `api/tests/Unit/Communications/HtmlEmailsTest.php`
- `api/tests/Unit/Community/PushMessageFactoryTest.php`
- `api/tests/Unit/ScheduleTest.php`
- `frontend/src/features/surveys/admin-surveys-api.ts`
- `frontend/src/features/surveys/admin-survey-results.tsx`
- `frontend/src/features/surveys/admin-survey-page.tsx`
- `frontend/src/features/surveys/admin-surveys.test.tsx`
- `frontend/src/features/surveys/survey-notices.test.tsx` (nouveau)
- `frontend/src/features/community/notification-center.tsx`
- `frontend/src/features/community/notification-content.ts`
- `frontend/src/features/auth/notification-preferences-card.tsx`

### Gates

- API : phpstan, cs-fixer, DDD, rector verts ; suite complète sur ParaTest 4 workers (voir PR).
- Front : typecheck, lint, jest (1466 tests), build verts.
- Pas de vérification visuelle : serveurs de dev arrêtés.
