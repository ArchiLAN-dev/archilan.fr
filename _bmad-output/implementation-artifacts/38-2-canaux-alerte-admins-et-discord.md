# Story 38.2: Canaux d'alerte - admins in-app et Discord

**Status:** ready-for-dev
**Epic:** 38 - Santé et mise à jour automatique des apworlds
**Date:** 2026-09-24
**Dépend de :** 38.1 (les incidents et les ids renvoyés par la réconciliation).

## Story

En tant qu'admin d'ArchiLAN,
je veux être prévenu dans le site et sur le salon Discord du staff dès qu'un incident apworld s'ouvre,
puis voir sur Discord qui s'en occupe et quand il est réglé,
afin que le problème soit pris en main sans que personne ait à surveiller une page.

## Contexte

38.1 ouvre des incidents mais ne prévient personne. Deux canaux ont été retenus par Jean (pas d'email) :

- **La notification in-app**, via `Community\Application\Support\Notifier`, adressée à tous les admins
  par `CommunityAdminIdsQueryInterface::adminUserIds()`. C'est exactement le mécanisme des comptes
  signalés à la modération (story 30.28, `EvaluateAccountEscalation`).
- **Un webhook Discord** vers un salon staff. Le canal n'existe pas : `DiscordBotClient` ne sait que
  gérer des rôles. Un webhook entrant Discord est un simple `POST` JSON sur une URL secrète, sans bot.

Le webhook sert aussi de **journal de suivi** : l'ouverture, la prise en charge (« un tel s'en occupe »)
et la clôture y apparaissent, dans l'ordre, là où le staff discute déjà.

## Critères d'acceptation métier

1. **Ouverture.** À l'ouverture d'un incident, chaque admin reçoit une notification in-app « Apworld en
   échec : {jeu} » qui mène à la page Santé des apworlds (38.3 ; en attendant, à la page admin du jeu).
2. **Discord, ouverture.** Le salon staff reçoit un message avec le jeu, le type d'incident, le début du
   hash, un résumé d'une ligne de l'erreur et le lien vers la page.
3. **Discord, prise en charge.** Quand un admin prend l'incident en charge, le salon reçoit
   « {admin} s'occupe de {jeu} ».
4. **Discord, clôture.** Quand l'incident est résolu (automatiquement ou par un admin) ou ignoré, le salon
   reçoit un message qui le dit, avec qui l'a clos le cas échéant.
5. **Une alerte par incident.** Une récurrence (38.1, AC 2) ne renvoie **aucune** alerte. Seules les
   transitions en envoient.
6. **Discord non configuré.** Sans URL de webhook, rien n'est envoyé sur Discord et rien ne casse : la
   notification in-app part quand même.
7. **Discord en panne.** Un échec d'envoi vers Discord ne bloque ni la réconciliation ni la notification
   in-app, et n'annule aucune transition d'incident.

## Critères d'acceptation techniques

8. **Envoi après commit.** Les alertes partent de messages asynchrones dispatchés **après** le `flush()`
   de la transition (AC-A4) : `NotifyApworldIncidentAdminsJob(incidentId)` pour l'in-app,
   `PostApworldIncidentToStaffChannelJob(incidentId, event)` pour Discord, `event` parmi `opened`,
   `acknowledged`, `resolved`, `ignored`. Deux jobs séparés : un retry de l'un ne rejoue pas l'autre.
9. **Relecture.** Chaque handler relit l'incident par son id et construit le message à partir de son état
   au moment de l'envoi. Un incident introuvable est ignoré sans erreur.
10. **Type de notification** `Notification::TYPE_APWORLD_INCIDENT_OPENED = 'apworld_incident_opened'`,
    payload `{incidentId, gameId, gameName, type}`. Rendu dans
    `frontend/src/features/community/notification-center.tsx` (`messageFor`, `hrefFor`).
11. **Port** `StaffAlertChannelInterface` (`GameSelection/Application/Port/`) avec
    `post(StaffAlert $alert): void`. `StaffAlert` est un record (titre, lignes, lien, niveau).
    Implémentations :
    - `DiscordWebhookStaffAlertChannel` (`Infrastructure/Http/`) : `POST` d'un embed Discord sur
      `DISCORD_STAFF_WEBHOOK_URL`, via `HttpClientInterface`, timeout court ;
    - `NullStaffAlertChannel` (`Infrastructure/Double/`) quand la variable est vide, choisi par la
      configuration de services et non par un `if` dans le handler.
12. **Contenu borné.** Le résumé d'erreur passe par `GenerationFailureParser::summarize()` et respecte les
    limites Discord (4096 caractères de description, 256 de titre). Aucun hash complet, aucun secret,
    aucune URL interne dans le message.
13. **Variable d'environnement** `DISCORD_STAFF_WEBHOOK_URL` documentée dans `.env.prod.example` et
    `api/.env` (vide), jamais commitée avec une vraie valeur.
14. **Origine des transitions.** La réconciliation (38.1) dispatche après son flush pour chaque incident
    ouvert et chaque incident résolu. La prise en charge, la résolution et l'ignorer manuels (38.3)
    dispatchent de la même façon : un seul point d'envoi par transition.
15. `composer gates` et `pnpm gates` passent.

## Ordre TDD

1. `tests/Unit/GameSelection/StaffAlertFactoryTest.php` - la mise en forme, pure :
   - `testOpenedAlertCarriesGameTypeShortHashAndOneLineSummary`
   - `testAcknowledgedAlertNamesTheAdmin`
   - `testResolvedAutomaticallySaysSoWithoutAnAdmin`
   - `testLongErrorIsTruncatedToDiscordLimits`
2. `tests/Unit/GameSelection/NotifyApworldIncidentAdminsHandlerTest.php`
   - `testNotifiesEveryAdminOnce`
   - `testMissingIncidentIsIgnored`
3. `tests/Unit/GameSelection/PostApworldIncidentToStaffChannelHandlerTest.php`
   - `testPostsTheAlertMatchingTheEvent`
   - `testChannelFailureIsLoggedAndSwallowed`
4. `tests/Unit/GameSelection/DiscordWebhookStaffAlertChannelTest.php` - `MockHttpClient` :
   - `testPostsAnEmbedToTheConfiguredUrl`
   - `testNon2xxResponseIsReportedAsFailure`
5. `tests/Unit/GameSelection/ReconcileApworldIncidentsHandlerTest.php`
   - `testDispatchesOneAdminAndOneStaffJobPerOpenedIncidentAfterFlush`
   - `testRecurrenceDispatchesNothing`
6. Frontend : `notification-center.test.tsx`
   - rendu du message et du lien de `apworld_incident_opened`.

## Tasks / Subtasks

- [ ] **Task 1** (AC 11, 12) - `StaffAlert`, fabrique de messages, port et implémentations.
- [ ] **Task 2** (AC 8, 9, 14) - Les deux jobs et leurs handlers, dispatch depuis la réconciliation.
- [ ] **Task 3** (AC 10) - Type de notification côté API et rendu frontend.
- [ ] **Task 4** (AC 13) - Variable d'environnement et câblage des services.
- [ ] **Task 5** (AC 15) - Gates des deux côtés.

## Dev Notes

- **Contextes.** `GameSelection` utilise `Community\Application\Support\Notifier` et
  `CommunityAdminIdsQueryInterface` comme `Sessions` le fait déjà pour `NotifyGenerationFailureJobHandler` :
  Application vers Application, autorisé par `app:architecture:ddd`.
- **`Notifier::notify()` ignore l'acteur** : ici il n'y a pas d'acteur humain, le payload n'en porte pas.
- **Discord.** Un webhook entrant accepte `{"embeds": [{"title", "description", "url", "color"}]}`.
  Le `username` peut être forcé (« ArchiLAN - santé des apworlds »). Répond `204` en cas de succès.
  Limites : 30 requêtes par minute par webhook, largement suffisant ici.
- **Retry.** Laisser le retry Messenger standard : un job Discord qui échoue trois fois part en
  `failed` sans rien casser d'autre (AC 7). L'AC 7 impose seulement qu'aucune transition ne soit
  annulée, ce que garantit l'envoi après commit.

### Project Structure Notes

- `api/src/GameSelection/Application/Port/StaffAlertChannelInterface.php`
- `api/src/GameSelection/Application/Support/StaffAlert.php`, `StaffAlertFactory.php`
- `api/src/GameSelection/Application/Message/NotifyApworldIncidentAdminsJob.php`, `PostApworldIncidentToStaffChannelJob.php`
- `api/src/GameSelection/Application/Handler/…Handler.php` (les deux)
- `api/src/GameSelection/Infrastructure/Http/DiscordWebhookStaffAlertChannel.php`
- `api/src/GameSelection/Infrastructure/Double/NullStaffAlertChannel.php`
- `api/src/Community/Domain/Entity/Notification.php` - nouvelle constante de type
- `frontend/src/features/community/notification-center.tsx`

### References

- [Source: api/src/Community/Application/Command/EvaluateAccountEscalation.php] - notification des admins
- [Source: api/src/Sessions/Application/Handler/NotifyGenerationFailureJobHandler.php] - notification post-commit depuis un autre contexte
- [Source: _bmad-output/implementation-artifacts/38-1-incidents-apworld.md]
