# Story 51.2 - Le joueur répond à un sondage

**Status:** ready-for-dev
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

- [ ] **Task 1** (AC 1-3, 7) - Migration, entités, port d'éligibilité, commande de réponse, requêtes, contrôleur.
- [ ] **Task 2** (AC 4, 8) - Encart sur la page de l'événement.
- [ ] **Task 3** (AC 5, 6, 8) - Page de réponse.
- [ ] **Task 4** (AC 9) - Gates verts, Dev Agent Record.
