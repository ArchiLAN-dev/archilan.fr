# Story 38.2: Canaux d'alerte - admins in-app et Discord

**Status:** review
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
10. **Type de notification** `apworld_incident_opened`, déclaré sur le job
    (`NotifyApworldIncidentAdminsJob::NOTIFICATION_TYPE`), comme `generation_failed` (9.41) et
    `tutorial_contribution_reviewed` le sont sur leur émetteur. Payload
    `{incidentId, gameId, gameName, incidentType}` (`incidentType` plutôt que `type`, qui désigne déjà
    le type de notification). Rendu dans `frontend/src/features/community/notification-center.tsx`
    (`messageFor`, `hrefFor`, exportées pour être testées).
11. **Port** `StaffAlertChannelInterface` (`GameSelection/Application/Port/`) avec
    `post(StaffAlert $alert): void`. `StaffAlert` est un record (titre, description, lien, niveau).
    Implémentations :
    - `DiscordWebhookStaffAlertChannel` (`Infrastructure/Http/`) : `POST` d'un embed Discord sur
      `DISCORD_STAFF_WEBHOOK_URL`, via `HttpClientInterface`, timeout court, `allowed_mentions` vide ;
    - `DisabledStaffAlertChannel` (`Infrastructure/Adapter/`, pas `Double/` : c'est un adaptateur de
      production) quand la variable est vide. Le choix est fait par `StaffAlertChannelFactory`, déclarée
      comme factory du service, et non par un `if` dans le handler.
12. **Contenu borné.** Le résumé d'erreur passe par `GenerationFailureParser::summarize()` et respecte les
    limites Discord (4096 caractères de description, 256 de titre). Aucun hash complet, aucun secret,
    aucune URL interne dans le message.
13. **Variable d'environnement** `DISCORD_STAFF_WEBHOOK_URL` documentée dans `.env.prod.example` et
    `api/.env` (vide), jamais commitée avec une vraie valeur.
14. **Origine des transitions.** `ApworldIncidentAlertDispatcher` (`Application/Support/`) envoie les
    alertes d'un résultat de réconciliation : ouverts, résolus, ignorés. Le handler planifié **et** la
    commande console l'utilisent, pour qu'un passage manuel alerte comme le passage planifié. La prise en
    charge, la résolution et l'ignorer manuels (38.3) dispatcheront de la même façon.
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
6. `tests/Unit/GameSelection/ReconcileApworldIncidentsCommandTest.php` (ajouté en cours de route)
   - `testAManualRunSendsTheSameAlertsAsTheScheduledOne`
   - `testRunnerUnavailableFailsAndSendsNothing`
7. Frontend : `notification-center.test.ts` (logique pure, pas de rendu)
   - message et lien de `apworld_incident_opened`, avec et sans nom de jeu, avec et sans id de jeu.

## Tasks / Subtasks

- [x] **Task 1** (AC 11, 12) - `StaffAlert`, fabrique de messages, port et implémentations.
- [x] **Task 2** (AC 8, 9, 14) - Les deux jobs et leurs handlers, dispatch depuis la réconciliation.
- [x] **Task 3** (AC 10) - Type de notification côté API et rendu frontend.
- [x] **Task 4** (AC 13) - Variable d'environnement et câblage des services.
- [x] **Task 5** (AC 15) - Gates des deux côtés.

## Dev Notes

- **Contextes.** `GameSelection` utilise `Community\Application\Support\Notifier` et
  `CommunityAdminIdsQueryInterface` comme `Sessions` le fait déjà pour `NotifyGenerationFailureJobHandler` :
  Application vers Application, autorisé par `app:architecture:ddd`.
- **`Notifier::notify()` ignore l'acteur** : ici il n'y a pas d'acteur humain, le payload n'en porte pas.
- **Discord.** Un webhook entrant accepte `{"embeds": [{"title", "description", "url", "color"}]}`.
  Le `username` peut être forcé (« ArchiLAN - santé des apworlds »). Répond `204` en cas de succès.
  Limites : 30 requêtes par minute par webhook, largement suffisant ici.
- **Pas de retry.** Retenu à l'implémentation : le handler Discord attrape l'échec, le journalise
  (`apworld_incidents.staff_alert_not_posted`) et s'arrête, comme `NotifyGenerationFailureJobHandler`
  (9.41). Un job qui repasse trois fois puis finit dans `async_failed` pour une panne Discord
  n'apporterait rien : l'incident, lui, est déjà enregistré et visible.
- **Mentions.** `allowed_mentions: {"parse": []}` : le texte d'erreur vient du code d'apworlds tiers, un
  `@everyone` qui s'y glisserait doit rester du texte.
- **Secret.** L'URL du webhook est le secret : elle n'apparaît jamais dans un message d'exception ni
  dans un log.

### Project Structure Notes

- `api/src/GameSelection/Application/Port/StaffAlertChannelInterface.php`
- `api/src/GameSelection/Application/Support/StaffAlert.php`, `StaffAlertFactory.php`
- `api/src/GameSelection/Application/Message/NotifyApworldIncidentAdminsJob.php`, `PostApworldIncidentToStaffChannelJob.php`
- `api/src/GameSelection/Application/Message/StaffAlertEvent.php`
- `api/src/GameSelection/Application/Handler/…Handler.php` (les deux)
- `api/src/GameSelection/Application/Support/StaffAlertLevel.php`, `ApworldIncidentAlertDispatcher.php`
- `api/src/GameSelection/Application/Exception/StaffAlertDeliveryException.php`
- `api/src/GameSelection/Infrastructure/Http/DiscordWebhookStaffAlertChannel.php`
- `api/src/GameSelection/Infrastructure/Adapter/DisabledStaffAlertChannel.php`, `StaffAlertChannelFactory.php`
- `api/config/services.yaml`, `api/config/packages/messenger.yaml` (routage `async` des deux jobs)
- `frontend/src/features/community/notification-center.tsx`

### References

- [Source: api/src/Community/Application/Command/EvaluateAccountEscalation.php] - notification des admins
- [Source: api/src/Sessions/Application/Handler/NotifyGenerationFailureJobHandler.php] - notification post-commit depuis un autre contexte
- [Source: _bmad-output/implementation-artifacts/38-1-incidents-apworld.md]

## Dev Agent Record

### Déroulé TDD

| Étape | Rouge | Vert |
|---|---|---|
| `StaffAlertFactory` | 8 échecs sur 9 | 9 tests |
| `NotifyApworldIncidentAdminsHandler` | 2 échecs sur 3 | 3 tests |
| `PostApworldIncidentToStaffChannelHandler` | 6 échecs sur 7 | 7 tests |
| `DiscordWebhookStaffAlertChannel` et sa factory | 7 échecs sur 9 | 9 tests |
| Dispatch après commit (handler planifié) | constructeur sans bus | 5 tests |
| Commande console (extraction du dispatcher) | classe absente, refactor d'un comportement déjà couvert | 2 tests |
| Frontend `messageFor` / `hrefFor` | 4 échecs, messages par défaut | 4 tests |

PHPStan a refusé les premiers espions (classes anonymes à propriété par référence) : remplacés par des
espions nommés (`SpyNotifier`, `SpyStaffAlertChannel`, `SpyMessageBus`, `WarningCollectingLogger`).

### Vérifications

- `composer gates` : vert, 2018 tests. `pnpm gates` : vert, 486 tests, build OK.
- `pnpm lint` remonte 10 avertissements `no-location-assign-relative-destination`, **tous dans des fichiers
  non touchés** par cette story (déjà présents sur `develop`, probablement depuis la montée de Next de
  `c4e150fb`).
- **Scénario de bout en bout** sur une copie de la base locale, jobs en `sync://` (pour ne pas pousser
  des messages inconnus au worker de `develop` via RabbitMQ), et un faux serveur Discord local :
  1. Crystal Project sur v0.17.0 : 2 incidents ouverts (Crystal Project et Beat Saber), **12
     notifications in-app** (6 admins x 2), **2 messages Discord** rouges avec jeu, version courte,
     résumé d'une ligne, lien, et `allowed_mentions` vide.
  2. Passage en v0.18.2 : **un seul** message Discord vert « Crystal Project : incident résolu
     automatiquement », aucune nouvelle notification in-app.
  3. Passage suivant sans changement : rien n'est envoyé (Beat Saber est une récurrence).
- Le résumé d'une ligne a donné la cause de Beat Saber sans ouvrir de log :
  `FileNotFoundError: [Errno 2] No such file or directory: '/Archipelago/data/ranked_maps.json'`.

### Écarts à la rédaction initiale

- Constante de type sur le job, clé `incidentType` dans le payload (AC 10).
- `DisabledStaffAlertChannel` dans `Adapter/`, choisi par `StaffAlertChannelFactory` (AC 11).
- Pas de retry Messenger sur Discord : échec journalisé et absorbé (Dev Notes).
- `ApworldIncidentAlertDispatcher`, ajouté pour que la commande console alerte aussi (AC 14).
- Le lien des alertes mène à la page admin du jeu : la story 38.3 le fera pointer sur la page Santé.

## Corrections de revue (2026-09-26)

- **Une panne Discord passagère n'efface plus d'alerte.** Le canal distingue l'erreur passagère (429, 5xx,
  Discord injoignable) du refus définitif. La première est rendue au transport
  (`StaffAlertTemporarilyUnavailableException`), qui la relance 3 fois avec un délai croissant puis la
  range dans le transport d'échec. Un refus reste journalisé et abandonné. Pas de
  `RecoverableExceptionInterface`, qui relancerait sans fin.
- **Les posts d'une même passe sont espacés** de 500 ms (`DelayStamp`) : la première passe après un
  déploiement peut ouvrir des dizaines d'incidents d'un coup, et un webhook Discord accepte environ cinq
  posts par deux secondes.
