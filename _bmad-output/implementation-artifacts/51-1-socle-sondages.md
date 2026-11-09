# Story 51.1 - Socle des sondages (API admin)

**Status:** review
**Epic:** 51 - Sondages (`_bmad-output/planning-artifacts/epics/epic-51-sondages.md`)
**Date:** 2026-11-09
**Branche:** `feature/epic-51-story-1-socle-sondages` (depuis `develop`)
**Dépend de:** rien. Prérequis de toutes les autres stories de l'epic.

## Story

En tant qu'admin,
je veux créer un sondage sur un événement, avec ses questions, son public, sa période et ses options, le modifier
tant qu'il est en brouillon puis le publier,
afin de préparer les questions que je pose aux joueurs.

## Contexte (cartographie du 2026-11-09)

Rien n'existe : ni question personnalisée, ni sondage.

Repères :

- Événement : `api/src/Events/Domain/Entity/Event.php` (`startsAt`, `endsAt` en `datetimetz_immutable`, statuts
  en constantes `draft`, `published`, `in-progress`, `completed`).
- Forme d'une commande admin à copier : `api/src/Content/Application/Command/ManagePartners.php`. Exceptions
  typées (`NotFoundException`, `ValidationException`, `ConflictException`) converties en HTTP par le listener de
  l'epic 35.
- Socle de contexte récent à copier : epic 46, `_bmad-output/implementation-artifacts/46-1-socle-notes.md`
  (étapes pour déclarer un contexte : dossiers, `DddArchitectureValidator::CONTEXTS`, `services.yaml`,
  `doctrine.yaml`, liste de `api/CLAUDE.md` vérifiée par `StandardsDocsMatchToolingTest`).
- Vérifier l'existence d'un événement depuis `Surveys` par un port (`Application/Port/`), implémenté en
  `Infrastructure/Adapter/` sur le dépôt des événements, comme `NoteTargetInterface` en 46.1.
- Dernière migration au 2026-11-09 : `Version20261109120000`. Prendre le numéro suivant le dernier présent sur
  `develop` au moment du commit.

## Décisions

1. **Nouveau contexte `Surveys`**, tables préfixées `survey`.
2. **Entités** (`Domain/Entity/`) :
   - `Survey` : `id`, `eventId`, `title` (1 à 120), `intro` (0 à 1000, texte brut), `audience` (énumération
     `SurveyAudience` : `registration`, `registrants`, `participants`), `anonymous` (bool), `sendEmail` (bool,
     vrai par défaut), `opensAt`, `closesAt`, `status` (`draft` / `published`, énumération `SurveyStatus`),
     `publishedAt`, `createdAt`, `updatedAt`.
   - `SurveyQuestion` : `id`, `surveyId`, `kind` (énumération `QuestionKind` : `single`, `multiple`, `text`,
     `rating`), `label` (1 à 200), `required`, `position`.
   - `SurveyOption` : `id`, `questionId`, `label` (1 à 80), `position`, `retiredAt` (null si active).
3. **Règles du domaine (pures)** :
   - `closesAt` après `opensAt`, sinon `survey_period_invalid`.
   - Une question `single` ou `multiple` a au moins 2 options actives à la publication
     (`survey_question_options_missing`). `text` et `rating` n'ont pas d'options.
   - Un sondage publié garde son `audience` et son `anonymous` : les modifier → 409 `survey_locked_field`.
   - Sur un sondage publié : on peut ajouter une question, ajouter une option, retirer une option, modifier titre,
     intro, période et `sendEmail`. On ne peut pas supprimer une question ni changer son `kind` (409
     `survey_question_locked`). **Renommer une option** n'est permis que tant qu'elle n'a aucune réponse
     (409 `survey_option_answered`) ; la vérification des réponses se fait côté application par un port de
     lecture, prêt pour 51.2 (aujourd'hui toujours zéro).
   - Un brouillon se supprime ; un sondage publié ne se supprime pas (409 `survey_published`).
4. **Période par défaut** proposée par l'API à la création si absente : `registration` et `registrants` =
   maintenant jusqu'au début de l'événement ; `participants` = fin de l'événement jusqu'à 7 jours après.
5. **Une commande `ManageSurveys`** et une requête `AdminSurveysQuery` (interface + `Dbal*`). Ids comme en 46.1
   (triables dans le temps).

## Critères d'acceptation

Toutes les routes : admin uniquement, 401 sans session, 403 pour un membre.

1. Migration réversible : tables `survey` (index `event_id`), `survey_question` (index `survey_id, position`),
   `survey_option` (index `question_id, position`).
2. `POST /api/v1/admin/events/{eventId}/surveys` → 201 `{data: {id}}`, sondage en brouillon. Événement absent →
   404 `event_not_found`.
3. `GET /api/v1/admin/events/{eventId}/surveys` → la liste (id, titre, public, statut, période, nombre de
   questions).
4. `GET /api/v1/admin/surveys/{id}` → le sondage complet, questions et options ordonnées (options retirées
   incluses avec `retired: true`).
5. `PATCH /api/v1/admin/surveys/{id}` (champs du sondage), routes de questions (ajout, modification, ordre,
   suppression en brouillon) et d'options (ajout, renommage, ordre, retrait), avec les refus de la décision 3.
6. `POST /api/v1/admin/surveys/{id}/publish` → 204 ; validations de publication ; déjà publié → 409
   `survey_already_published`.
7. `DELETE /api/v1/admin/surveys/{id}` → 204 pour un brouillon, 409 sinon.
8. Id inconnu → 404 `survey_not_found`.

## Tests et gates

9. Unitaires : bornes, période, publication (options manquantes), champs figés, règles des options.
10. Fonctionnels : parcours complet (créer, questions, options, publier, modifier après publication), refus, 401 /
    403 sur chaque route.
11. `composer gates` vert (y compris `app:architecture:ddd` et `StandardsDocsMatchToolingTest`).

## Tasks / Subtasks

- [x] **Task 1** (AC 9) - Contexte `Surveys` déclaré, énumérations, entités, tests unitaires.
- [x] **Task 2** (AC 1) - Migration.
- [x] **Task 3** (AC 2, 5-7) - Dépôts, port événement, `ManageSurveys`.
- [x] **Task 4** (AC 3, 4) - `AdminSurveysQuery`.
- [x] **Task 5** (AC 2-8, 10) - Contrôleur admin et tests fonctionnels.
- [x] **Task 6** (AC 11) - Gates verts, Dev Agent Record.

## Dev Notes

### Hors périmètre

- Le front admin de création du sondage : à livrer avec 51.4 (même écran « Sondages » de l'événement), ou dans
  cette story si le temps le permet. À trancher au moment du dev avec Jean.
- Réponses, diffusion, résultats : 51.2 à 51.4.

### Garde-fous (`api/CLAUDE.md`)

- Domaine pur (pas d'horloge ni d'aléa), pas de `Connection` dans l'Application, DBAL QueryBuilder pour les
  lectures, un seul appel Application par action de contrôleur, commandes qui renvoient `void` ou un id.

## Dev Agent Record

### Agent Model Used

Claude Opus 5.5

### Completion Notes List

- Contexte `Surveys` déclaré partout (`DddArchitectureValidator::CONTEXTS` et la liste du test du validateur,
  `services.yaml` : exclusion du domaine et 4 alias, `doctrine.yaml`, liste de `api/CLAUDE.md`).
- Domaine : `Survey` (`draft`, `revise`, `publish`, `assertDeletable`, `touch`), `SurveyQuestion` (`ask`, `revise`,
  `moveTo`), `SurveyOption` (`offer`, `rename`, `retire`, `moveTo`), énumérations `SurveyAudience`, `SurveyStatus`,
  `QuestionKind` (`hasOptions()`).
- Application : `ManageSurveys` (13 opérations : sondage, questions, options, ordres), entrée `SurveyDraft`,
  `AdminSurveyQuery`, ports `SurveyEventInterface` (période de l'événement) et `SurveyAnswerCountInterface`.
- Infrastructure : `DoctrineSurveyRepository`, `DbalAdminSurveyQuery` (3 requêtes : sondage, questions, options
  groupées), `RepositorySurveyEvent`, `UnansweredSurveyOptions` (toujours « pas de réponse », à remplacer en 51.2
  par une lecture de `survey_answer`).
- Choix pris en cours de dev :
  - **Création de questions avec leurs options** en un appel (`options` facultatif), pour ne pas multiplier les
    allers-retours de l'écran admin.
  - **Retrait d'option** : supprimée dans un brouillon, retirée (`retired_at`) dans un sondage publié.
  - **Passage d'une question à choix en texte ou en note** (brouillon) : ses options sont supprimées.
  - **Public par défaut** : `registrants`, `sendEmail` vrai, `anonymous` faux.
  - **Période par défaut** : `registration` et `registrants`, de maintenant au début de l'événement (7 jours si
    l'événement a commencé) ; `participants`, de la fin de l'événement à 7 jours après.
  - Le libellé d'une question reste modifiable après publication (seuls la suppression et le changement de type
    sont refusés), comme la story le prévoit.
- Front admin de création non livré : il viendra avec l'écran « Sondages » de 51.4 (ou une story dédiée).
- Migration `Version20261109120001`.
- Gates : `composer gates:fast` vert (phpstan, cs-fixer, DDD, rector, 3651 tests).

### File List

- `api/migrations/Version20261109120001.php` (nouveau)
- `api/src/Surveys/Domain/Entity/Survey.php` (nouveau)
- `api/src/Surveys/Domain/Entity/SurveyQuestion.php` (nouveau)
- `api/src/Surveys/Domain/Entity/SurveyOption.php` (nouveau)
- `api/src/Surveys/Domain/Enum/SurveyAudience.php` (nouveau)
- `api/src/Surveys/Domain/Enum/SurveyStatus.php` (nouveau)
- `api/src/Surveys/Domain/Enum/QuestionKind.php` (nouveau)
- `api/src/Surveys/Domain/Repository/SurveyRepositoryInterface.php` (nouveau)
- `api/src/Surveys/Application/Command/ManageSurveys.php` (nouveau)
- `api/src/Surveys/Application/Command/SurveyDraft.php` (nouveau)
- `api/src/Surveys/Application/Query/AdminSurveyQuery.php` (nouveau)
- `api/src/Surveys/Application/Query/AdminSurveyQueryInterface.php` (nouveau)
- `api/src/Surveys/Application/Port/SurveyEventInterface.php` (nouveau)
- `api/src/Surveys/Application/Port/SurveyAnswerCountInterface.php` (nouveau)
- `api/src/Surveys/Application/Support/SurveyEventPeriod.php` (nouveau)
- `api/src/Surveys/Infrastructure/Doctrine/DoctrineSurveyRepository.php` (nouveau)
- `api/src/Surveys/Infrastructure/Dbal/DbalAdminSurveyQuery.php` (nouveau)
- `api/src/Surveys/Infrastructure/Adapter/RepositorySurveyEvent.php` (nouveau)
- `api/src/Surveys/Infrastructure/Adapter/UnansweredSurveyOptions.php` (nouveau)
- `api/src/Surveys/Presentation/Controller/AdminSurveyController.php` (nouveau)
- `api/src/Shared/Application/Support/DddArchitectureValidator.php` (modifié)
- `api/config/services.yaml` (modifié)
- `api/config/packages/doctrine.yaml` (modifié)
- `api/CLAUDE.md` (modifié)
- `api/tests/Unit/DddArchitectureValidatorTest.php` (modifié)
- `api/tests/Unit/Surveys/SurveyTest.php` (nouveau)
- `api/tests/Unit/Surveys/SurveyQuestionTest.php` (nouveau)
- `api/tests/Unit/Surveys/SurveyOptionTest.php` (nouveau)
- `api/tests/Functional/AdminSurveyTest.php` (nouveau)
