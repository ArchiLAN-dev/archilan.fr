# Story 51.6 - Des résultats de sondage lisibles à 57 inscrits

**Status:** review
**Epic:** 51 - Sondages (`_bmad-output/planning-artifacts/epics/epic-51-sondages.md`)
**Date:** 2026-11-10
**Branche:** `feature/epic-51-story-6-resultats-sondage` (depuis `feature/epic-52-story-10-presences-longues`)
**Dépend de:** 51.4 (résultats). Front seul : les routes `results`, `respondents` et `non-respondents` suffisent.

## Story

En tant qu'orga d'un événement à 57 inscrits,
je veux lire chaque question avec son total puis le détail de qui a répondu quoi, et filtrer les réponses,
afin de ne plus faire défiler une carte par inscrit pour trouver une information.

## Contexte

Retour de Jean le 2026-11-10 : « on a 57 personnes sur notre prochain event, c'est pas simple de tout lister comme ça
sur la même page -> Question/Résultats total et liste détaillée en dessous ». L'onglet Résultats de 51.4
(`features/surveys/admin-survey-results.tsx`) empile sur une seule page : les totaux par question, la liste des
non-répondants, puis une carte par répondant avec toutes ses réponses (57 cartes).

## Décisions

1. Trois vues dans l'onglet Résultats : **Par question** (par défaut), **Par inscrit (n)** (sondage nominatif
   seulement), **Pas encore répondu (n)** (avec la relance de 51.3).
2. **Par question** : chaque question garde son total (barres, moyenne, textes), puis un **détail repliable** « Qui a
   répondu quoi (n) » :
   - choix : un groupe par option, avec les pseudos (lien vers l'inscription) ;
   - note : un groupe par note, de 5 à 1 ;
   - texte : chaque texte avec son auteur.
   Cliquer une option (ou une note) dans le total ouvre le détail filtré sur elle ; un second clic retire le filtre.
   Sondage anonyme : pas de détail par personne (inchangé : totaux, textes triés sous condition de 51.4).
3. **Par inscrit** : un tableau compact, une ligne par répondant, une colonne par question (texte tronqué, entier au
   survol), pseudo collé à gauche au défilement horizontal, date de réponse. Filtres : recherche par pseudo, et
   « Question → réponse » (une option d'une question à choix). Compteur « n sur m ».
4. Rien ne change côté API ni pour les joueurs.

## Critères d'acceptation

1. Les trois vues existent ; « Par inscrit » n'existe pas pour un sondage anonyme.
2. Chaque question montre son total puis un détail repliable par option / note / texte, avec les pseudos liés.
3. Cliquer une option ou une note filtre le détail de cette question sur elle (`aria-pressed`), un second clic l'enlève.
4. Le tableau par inscrit filtre par pseudo (sans accents ni casse) et par réponse à une question ; il dit combien de
   lignes restent.
5. Tests : groupes du détail (choix, note, texte, option retirée), filtres du tableau, vues selon l'anonymat.
6. `pnpm gates` vert.

## Tâches

- [x] Regroupement des répondants par réponse (fonction pure).
- [x] Détail repliable et filtre par option dans chaque question.
- [x] Tableau par inscrit avec ses filtres.
- [x] Trois vues dans le panneau.
- [x] Tests, gates. Vérification visuelle non faite : aucun sondage dans la base locale.

## Dev Agent Record

- `admin-survey-results.tsx` : `ResultsViews` (trois vues, onglets `aria-pressed` ; un sondage anonyme retombe sur
  « Par question »), `answerGroups` (pure : option, note de 5 à 1, textes avec auteur), `filterRespondents` (pure :
  pseudo sans accents ni casse, `questionId:optionId`). Chaque question : total, options et notes cliquables quand
  le détail existe, détail replié « Qui a répondu quoi (n) » / « Lire les réponses (n) ». Tableau par inscrit :
  pseudo collé à gauche, cellules tronquées sur deux lignes avec le texte entier au survol, compteur « n sur m ».
- Les exports de 51.4 restent (`SurveyResultsView`, `RespondentList`, `NonRespondentList`, `RemindButton`,
  `answerText`) ; `SurveyResultsView` sans répondants s'affiche comme avant.
- Tests : `admin-surveys.test.tsx` (+5). Front : typecheck, lint, 1533 tests, build verts.
