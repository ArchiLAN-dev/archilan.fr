# Story 47.10 - Correctifs de la revue de la v0.66.0 (hotfix v0.66.1)

**Status:** review

## Contexte

Revue de code de la release v0.66.0 (epic 53 « Mon event » et diffusion locale de l'epic 47, 168 fichiers), faite
avant sa mise en production le 2026-11-12. Jean a demandé de tout corriger en hotfix v0.66.1.

## Acceptance Criteria

1. **Mail de la veille** : il part le jour calendaire (Paris) qui précède la date de début, à partir de 10 h, quelle que
   soit l'heure du début. Plus d'annonce « C'est demain ! » deux jours avant un début matinal.
2. **Lecture des flux de la LAN** : MediaMTX demande au site l'autorisation de chaque lecture ; seule la régie, avec le
   jeton régie de l'événement, lit un flux. La liste publique `broadcast-streams` ne donne les écrans de la LAN qu'avec
   ce jeton (`?regie=`). Les liens de l'écran régie de l'admin le portent.
3. **Clé régénérée** : un flux ouvert avec une clé remplacée depuis est coupé par l'agent à son rapport suivant.
4. **Inscrit qui n'a plus le droit de publier** (annulé, diffusion refusée, banni, hors fenêtre) : son flux n'est pas
   compté en direct et l'agent le coupe. La signature d'un rapport est vérifiée avant toute lecture (plus de 404/401
   qui révèle un événement).
5. **Réordonner les guides** avec une liste partielle ne crée plus de positions en double.
6. **Rafraîchissements** : la carte « Diffuser pour la régie », le hub admin et la liste des inscriptions ne relisent
   toutes les 15 s que s'il y a un serveur média ; la route publique ne lit rien de la LAN sans jeton ; un seul flux lu
   pour « La régie te reçoit ».
7. **Menu du compte** : l'entrée « Mon event » n'est lue qu'à l'ouverture du menu.
8. **Duplications** : la définition d'un sondage ouvert et le suivi des flux sont écrits une seule fois.
9. **Code mort** : le libellé « Confirmer ton inscription » du mail de la veille, inatteignable, est retiré.

## Tasks

- [x] **Task 1** (AC 1) - `SendEventEveRemindersHandler` : jour calendaire de Paris, à partir de 10 h ; tests.
- [x] **Task 2** (AC 2) - jeton régie dérivé d'APP_SECRET, `media-auth` accepte `read` avec ce jeton, `mediamtx.yml`
  n'exclut plus la lecture, `?regie=` sur `broadcast-streams`, lecteur WHEP en Bearer, liens de l'écran régie.
- [x] **Task 3** (AC 3, 4) - session MediaMTX et version de clé sur `LocalStream` (migration), liste `kick` dans la
  réponse au rapport, l'agent coupe par l'API de MediaMTX.
- [x] **Task 4** (AC 5 à 9) - réordonnancement, intervalles de rafraîchissement, menu du compte, `DbalOpenSurveys`,
  `LocalStreamsState::followUp`.
- [x] **Task 5** - tests, gates, essai réel MediaMTX.

## Dev Agent Record

### Completion Notes

- Essai réel avec MediaMTX 1.21.3 et l'API locale : lecture WHEP refusée sans jeton ou avec la clé d'un joueur (401),
  acceptée avec le jeton régie ; clé régénérée : l'agent coupe le flux SRT en un passage, l'ancienne clé est refusée,
  une nouvelle session avec la nouvelle clé n'est pas coupée. Données de dev restaurées.
- Les textes du guide `diffusion` (« Rien n'est public : seule la régie le voit », « l'ancienne cesse aussitôt de
  marcher ») redeviennent vrais : pas de nouvelle migration de contenu.
- Limite connue : une session ouverte avec l'ancienne clé dans les quelques secondes qui précèdent la régénération,
  et pas encore vue par l'agent, est enregistrée avec la nouvelle version et n'est pas coupée.
- Internet coupé : une nouvelle lecture est refusée (elle passe maintenant par le site) ; flux et lecteurs déjà ouverts
  continuent. L'écran régie dépend déjà du site pour sa liste.
- Gates : API (rector, cs-fixer, phpstan, ddd, rector dry-run, 3891 tests en parallèle) et front (typecheck, lint, 1661
  tests, build) verts.

### File List

- `api/migrations/Version20261112100000.php` (nouveau)
- `api/src/Content/Application/Command/ManageHelpGuides.php`
- `api/src/Registrations/Application/Handler/SendEventEveRemindersHandler.php`
- `api/src/Streaming/Application/Command/LocalStreamsReport.php`
- `api/src/Streaming/Application/Command/ReportLocalStreams.php`
- `api/src/Streaming/Application/Query/ParticipantStreamsView.php`
- `api/src/Streaming/Application/Service/AdminLocalStreams.php`
- `api/src/Streaming/Application/Service/LocalStreamsState.php`
- `api/src/Streaming/Application/Service/MediaPublishAccess.php`
- `api/src/Streaming/Application/Service/MediaPublishAuthorization.php`
- `api/src/Streaming/Application/Support/MediaPublishCredentials.php`
- `api/src/Streaming/Domain/Entity/LocalStream.php`
- `api/src/Streaming/Domain/Repository/LocalStreamRepositoryInterface.php`
- `api/src/Streaming/Infrastructure/Doctrine/DoctrineLocalStreamRepository.php`
- `api/src/Streaming/Presentation/Controller/LocalStreamsController.php`
- `api/src/Streaming/Presentation/Controller/ParticipantStreamsController.php`
- `api/src/Surveys/Infrastructure/Dbal/DbalOpenSurveys.php` (nouveau)
- `api/src/Surveys/Infrastructure/Dbal/DbalPendingRegistrationSurveyQuery.php`
- `api/src/Surveys/Infrastructure/Dbal/DbalRegistrantSurveysQuery.php`
- `api/tests/Functional/LocalStreamsReportTest.php`
- `api/tests/Functional/MediaPublishTest.php`
- `api/tests/Functional/ParticipantStreamsTest.php`
- `api/tests/Functional/SendEventEveRemindersTest.php`
- `api/tests/Unit/Streaming/LocalStreamTest.php`
- `frontend/src/app/(overlay)/o/whep-essai/page.tsx`
- `frontend/src/features/admin/admin-events-api.ts`
- `frontend/src/features/admin/admin-local-streams.test.tsx`
- `frontend/src/features/admin/admin-registration-dashboard.tsx`
- `frontend/src/features/admin/broadcast-screen-links.tsx`
- `frontend/src/features/admin/event-hub/admin-event-hub.tsx`
- `frontend/src/features/auth/user-menu.tsx`
- `frontend/src/features/streaming/broadcast-screen.test.tsx`
- `frontend/src/features/streaming/broadcast-screen.tsx`
- `frontend/src/features/streaming/media-publish-card.tsx`
- `frontend/src/features/streaming/participant-streams-api.ts`
- `frontend/src/features/streaming/whep-player.test.tsx`
- `frontend/src/features/streaming/whep-player.tsx`
- `ops/lan-media/README.md`
- `ops/lan-media/agent.py`
- `ops/lan-media/mediamtx.yml`
