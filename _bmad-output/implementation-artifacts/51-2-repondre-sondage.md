# Story 51.2 - Le joueur répond à un sondage

**Status:** review
**Epic:** 51 - Sondages (`_bmad-output/planning-artifacts/epics/epic-51-sondages.md`)
**Date:** 2026-11-09
**Branche:** `feature/epic-51-story-2-repondre-sondage` (depuis `develop`)
**Dépend de:** 51.1. Prérequis de 51.3, 51.4 et 51.5.

## Story

En tant que joueur concerné par un sondage,
je veux voir sur la page de l'événement qu'un sondage m'attend et y répondre,
afin que l'orga ait l'information dont elle a besoin.

## Contexte (cartographie du 2026-11-09)

- Inscription : `api/src/Registrations/Domain/Entity/Registration.php`. Statut en constantes : `reserved`
  (active) et `cancelled`. Confirmée = `reserved` avec `submittedAt` non nul. Dépôt
  `RegistrationRepositoryInterface::findByEventAndUser()`.
- Participation : `session` (`event_id`, statuts dont `running`, `finished`) et `session_slot`
  (`registration_id`, `session_id`). `api/src/Community/Infrastructure/Dbal/DbalEventParticipationQuery.php`
  exige un goal atteint : ne pas la réutiliser, écrire la requête de l'epic (un slot dans une session lancée de
  l'événement). Figer la liste des statuts de session « lancée » en lisant
  `api/src/Sessions/Domain/Entity/Session.php`.
- Page publique : `frontend/src/app/(public)/evenements/[eventSlug]/page.tsx`. Le SSR est anonyme ; les blocs
  propres au membre sont des composants client. Modèle : `frontend/src/features/events/event-registration-cta.tsx`
  (`useQuery` sur `["event-my-registration", eventId]`, se tait pour un visiteur). Client API :
  `frontend/src/features/events/events-api.ts`.

## Décisions

1. **Éligibilité** (port de lecture dans `Surveys`, implémenté côté `Registrations` / `Sessions`) :
   - `registration` et `registrants` : inscription `reserved` sur l'événement ;
   - `participants` : inscription `reserved` confirmée avec un slot dans une session lancée de l'événement ; sans
     aucune session lancée sur l'événement, toute inscription `reserved` confirmée.
   - Sondage publié et période en cours (`opensAt <= maintenant < closesAt`).
2. **Stockage** :
   - `survey_participation` : `surveyId`, `userId`, `respondedAt`, unique sur (`surveyId`, `userId`). Toujours
     nominatif.
   - `survey_response` : `id`, `surveyId`, `userId` (null si sondage anonyme), puis `survey_answer` :
     `responseId`, `questionId`, `optionId` (choix), `text`, `rating`.
   - Sondage anonyme : `survey_response` sans `userId` et sans date plus précise que le jour, écrit dans la même
     transaction que la participation ; aucune colonne ne permet de relier les deux.
3. **Validation** : questions obligatoires remplies (`survey_answer_required`), choix unique = une option active
   de la question, choix multiple = au moins une option active, texte 1 à 1000, note entière de 1 à 5.
4. **Modification** : sondage nominatif → la réponse se remplace jusqu'à la fermeture. Sondage anonyme → une
   seule réponse, définitive (409 `survey_already_answered`).
5. Le port de 51.1 « l'option a-t-elle des réponses » est branché sur `survey_answer`.

## Critères d'acceptation

1. `GET /api/v1/events/{eventId}/my-surveys` (connecté) → les sondages ouverts qui concernent le membre, avec
   `answered` (bool) et `closesAt`. Liste vide pour un membre non concerné.
2. `GET /api/v1/surveys/{id}/my-response` → le sondage (questions, options actives) et, pour un sondage
   nominatif, la réponse déjà donnée. Non concerné → 403 `survey_not_eligible` ; fermé ou non publié → 409
   `survey_closed`.
3. `PUT /api/v1/surveys/{id}/my-response` → 204, avec les validations des décisions 3 et 4.
4. **Encart** sur la page publique de l'événement : « L'orga a une question pour toi » avec le titre du sondage,
   la date de fermeture et un bouton « Répondre ». Rien pour un visiteur ni pour un membre qui a déjà répondu à
   tout.
5. **Page de réponse** `/evenements/[eventSlug]/sondages/[surveyId]` : intro, questions dans l'ordre, champ
   adapté à chaque type (boutons radio, cases, texte, 5 étoiles ou 5 boutons), questions obligatoires signalées.
   Sondage anonyme : mention « Tes réponses sont anonymes. On sait seulement que tu as répondu, pour ne pas te
   relancer. » et avertissement que la réponse ne se modifie pas.
6. Confirmation après envoi, retour à l'événement ; un sondage nominatif rouvert montre la réponse donnée.

## Tests et gates

7. Fonctionnels : éligibilité des trois publics (dont le repli sans session), période, validations, modification
   nominative, anonyme définitif et sans lien vers l'utilisateur en base.
8. Front : tests de l'encart (visiteur, concerné, déjà répondu) et du formulaire (types, obligatoires, anonyme).
9. `composer gates` et `pnpm gates` verts.

## Tasks / Subtasks

- [x] **Task 1** (AC 1-3, 7) - Migration, entités, port d'éligibilité, commande de réponse, requêtes, contrôleur.
- [x] **Task 2** (AC 4, 8) - Encart sur la page de l'événement.
- [x] **Task 3** (AC 5, 6, 8) - Page de réponse.
- [x] **Task 4** (AC 9) - Gates verts, Dev Agent Record.

## Dev Agent Record

### Agent Model Used

Claude Opus 5.5

### Completion Notes List

- Branche partie de `feature/epic-51-story-1-socle-sondages` (51.1 pas encore mergée) : la PR cible cette branche.
- Domaine : `SurveyParticipation` (unique sur sondage + membre), `SurveyResponse` (`nominative` / `anonymous`, jour
  seulement), `SurveyAnswer` (une ligne par option choisie, ou un texte, ou une note), service pur `AnswerSheet` et
  valeur `GivenAnswer`, `Survey::isOpenAt()`.
- Application : `AnswerSurvey` (entrée `SubmittedAnswer`), `MemberSurveyQuery`, `SurveyGate` (brouillon 404, hors
  public 403, hors période 409), `SurveyAudienceQueryInterface`.
- Infrastructure : `DbalSurveyAudienceQuery`, `DbalMemberSurveyQuery`, `DoctrineSurveyResponseRepository`, et
  `DbalSurveyAnswerCount` qui remplace `UnansweredSurveyOptions` de 51.1 (une option choisie ne se renomme plus).
- **Session lancée** (figé en lisant `Session.php`) : `running`, `idle`, `restarting`, `stopped`, `crashed`,
  `finished`, c'est-à-dire toute session passée par `running`. Une session `failed` n'a jamais eu de joueurs.
- **Anonymat** : la réponse n'a ni membre ni horodatage (le jour seulement), et ses ids (réponse et réponses) sont
  aléatoires, pas triables dans le temps, pour ne pas pouvoir les recouper avec la participation écrite dans la
  même unité de travail.
- Décisions prises en cours de dev :
  - une réponse qui ne répond à aucune question est refusée (`survey_answer_empty`) ;
  - une question laissée vide (aucune option, texte blanc, pas de note) compte comme sans réponse ;
  - l'encart ne liste que les sondages non répondus ; un sondage nominatif déjà répondu se rouvre par son lien.
- Front : `features/surveys/` (`surveys-api.ts`, `EventSurveyBanner` sur la page de l'événement, `SurveyResponseGate`
  et `SurveyResponseForm`), page `/evenements/[eventSlug]/sondages/[surveyId]` (redirection vers la connexion
  pour un visiteur).
- Migration `Version20261109120002`.

### File List

- `api/migrations/Version20261109120002.php` (nouveau)
- `api/src/Surveys/Domain/Entity/Survey.php` (modifié : `isOpenAt`)
- `api/src/Surveys/Domain/Entity/SurveyParticipation.php` (nouveau)
- `api/src/Surveys/Domain/Entity/SurveyResponse.php` (nouveau)
- `api/src/Surveys/Domain/Entity/SurveyAnswer.php` (nouveau)
- `api/src/Surveys/Domain/Repository/SurveyResponseRepositoryInterface.php` (nouveau)
- `api/src/Surveys/Domain/Service/AnswerSheet.php` (nouveau)
- `api/src/Surveys/Domain/ValueObject/GivenAnswer.php` (nouveau)
- `api/src/Surveys/Application/Command/AnswerSurvey.php` (nouveau)
- `api/src/Surveys/Application/Command/SubmittedAnswer.php` (nouveau)
- `api/src/Surveys/Application/Query/MemberSurveyQuery.php` (nouveau)
- `api/src/Surveys/Application/Query/MemberSurveyQueryInterface.php` (nouveau)
- `api/src/Surveys/Application/Query/SurveyAudienceQueryInterface.php` (nouveau)
- `api/src/Surveys/Application/Support/SurveyGate.php` (nouveau)
- `api/src/Surveys/Infrastructure/Dbal/DbalMemberSurveyQuery.php` (nouveau)
- `api/src/Surveys/Infrastructure/Dbal/DbalSurveyAudienceQuery.php` (nouveau)
- `api/src/Surveys/Infrastructure/Dbal/DbalSurveyAnswerCount.php` (nouveau)
- `api/src/Surveys/Infrastructure/Doctrine/DoctrineSurveyResponseRepository.php` (nouveau)
- `api/src/Surveys/Infrastructure/Adapter/UnansweredSurveyOptions.php` (supprimé)
- `api/src/Surveys/Presentation/Controller/MemberSurveyController.php` (nouveau)
- `api/config/services.yaml` (modifié)
- `api/tests/Unit/Surveys/AnswerSheetTest.php` (nouveau)
- `api/tests/Functional/MemberSurveyTest.php` (nouveau)
- `frontend/src/features/surveys/surveys-api.ts` (nouveau)
- `frontend/src/features/surveys/event-survey-banner.tsx` (nouveau)
- `frontend/src/features/surveys/survey-response-form.tsx` (nouveau)
- `frontend/src/features/surveys/surveys.test.tsx` (nouveau)
- `frontend/src/app/(public)/evenements/[eventSlug]/sondages/[surveyId]/page.tsx` (nouveau)
- `frontend/src/app/(public)/evenements/[eventSlug]/page.tsx` (modifié : encart)
