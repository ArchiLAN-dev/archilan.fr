# Story 32.20 - Retirer la page de résultats au profit du récap

## Status

Done

## Story

**En tant que** propriétaire d'une partie privée,
**je veux** que le réglage « récap privé » protège réellement ma partie,
**afin que** mes participants, mes jeux et mes temps ne soient pas lisibles par n'importe qui.

## Contexte

La page `/runs/{sessionId}/resultats` fait double emploi depuis la refonte du récap (stories 32.15
à 32.19) : la durée, la date et le nombre de slots vivent maintenant dans le bandeau de chiffres
clés, et la répartition terminés / non terminés / invalidés est une colonne du tableau comparatif,
qui montre en plus le rang, le jeu, les checks, les échanges et les slots libérés.

Mais elle pose un problème plus sérieux que la redondance. Comparons les deux endpoints :

- `GET /api/v1/parties/{sessionId}/recap` applique `SessionRecapAudience::canView()` - un récap
  d'événement public l'est, un récap de run privée n'est servi qu'au propriétaire, aux participants,
  ou à tous si le propriétaire l'a publié.
- `GET /api/v1/runs/{id}/results` appelle `RunResultsQuery::execute($id)` : **aucun paramètre de
  spectateur, aucun filtre, aucune authentification sur le contrôleur.**

Le bouton « Rendre privé » ne ferme donc qu'une des deux portes. La seconde expose les participants,
leurs jeux et leurs temps de toute session terminée dont on connaît l'identifiant - runs privées et
événements non publics compris.

## Acceptance Criteria

**AC1 - La page de résultats disparaît.** `/runs/{runId}/resultats` et la route API
`GET /api/v1/runs/{id}/results` sont retirées. `RunResultsQuery` **reste** : le récap s'en sert pour
composer son podium.

**AC2 - Les liens pointent vers le récap.** Les trois entrées existantes - flux d'activité de la
communauté, historique de parties sur un profil joueur (deux endroits) - mènent à `/parties/{id}`.

**AC3 - Une partie non lisible n'est pas cliquable.** Quand le spectateur n'a pas le droit de lire
le récap (run privée dont il n'est ni propriétaire ni participant, événement non public), ou quand
aucun récap n'existe encore, la ligne s'affiche en texte simple, sans lien. Jamais un lien qui mène
à une page d'erreur.

**AC4 - L'accessibilité est calculée côté serveur**, avec la règle existante
(`SessionRecapAudience`), et transmise dans la charge utile des listes. Le front ne doit pas la
deviner ni la sonder ligne par ligne.

**AC5 - Le récap ne renvoie plus vers une page morte.** Son lien « Retour aux résultats » est
remplacé, et le lien « classement communautaire » que portait la page de résultats est recueilli.

**AC6 - Quality gates.** `composer gates` et `pnpm gates` verts.

## Tasks / Subtasks

- [x] Task 1: service Sessions exposant, pour une liste d'identifiants de session et un spectateur,
      lesquels ont un récap **lisible et existant** (AC3, AC4).
- [x] Task 2: `recapAccessible` dans les entrées du flux communauté (le viewer y est déjà) (AC4).
- [x] Task 3: `recapAccessible` dans l'historique joueur - le spectateur doit y être ajouté (AC4).
- [x] Task 4: suppression de `RunResultsController` et de la page front + son module d'API (AC1).
- [x] Task 5: liens conditionnels dans `community-activity` et `player-profile-page` (AC2, AC3).
- [x] Task 6: en-tête du récap - retrait du lien mort, ajout du classement (AC5).
- [x] Task 7: tests - lisible/non lisible/absent, et non-régression du récap (AC3, AC4).
- [x] Task 8: gates (AC6).

## Dev Notes

**Ne pas réécrire la règle d'accès.** `SessionRecapAudience` porte déjà exactement la bonne
sémantique et son docblock dit pourquoi elle est unique : « une divergence entre deux copies de
cette règle est précisément le bug qu'on évite ». Le nouveau service l'appelle, il ne la duplique
pas.

**Deux conditions, pas une.** « Cliquable » demande que le récap soit *lisible* **et** qu'il
*existe*. Une session terminée avant la story 32.1 passe la première condition et échoue la seconde ;
la traiter comme lisible produirait exactement le lien mort qu'AC3 interdit.

**Coût des requêtes.** L'accessibilité est évaluée pour une liste, jamais ligne par ligne : une
lecture groupée des sessions et des récaps concernés, puis la règle en mémoire. Sur un profil
joueur qui pagine 20 entrées, la version naïve ferait 60 requêtes.

**Ce que la suppression ne casse pas.** `/resultats` est déjà exclu du sitemap et du `robots.txt`,
et deux tests le vérifient - il n'y a donc rien à perdre côté référencement, et aucune redirection
SEO à prévoir. Les liens déjà partagés (Discord) mèneront à un 404 : prévoir une redirection
`/runs/{id}/resultats` → `/parties/{id}` est possible, mais elle renverrait un visiteur sans droits
vers une page « Récap introuvable », ce qui reste préférable à l'exposition actuelle.

**Hors périmètre.** La page de résultats d'un *événement*
(`/evenements/{slug}/resultats`, endpoint `/events/{eventId}/session/results`) est une surface
distincte et n'est pas touchée.
