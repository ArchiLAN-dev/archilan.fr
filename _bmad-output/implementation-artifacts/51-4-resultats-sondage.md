# Story 51.4 - Résultats d'un sondage (admin)

**Status:** ready-for-dev
**Epic:** 51 - Sondages (`_bmad-output/planning-artifacts/epics/epic-51-sondages.md`)
**Date:** 2026-11-09
**Branche:** `feature/epic-51-story-4-resultats-sondage` (depuis `develop`)
**Dépend de:** 51.2. Indépendante de 51.3.

## Story

En tant qu'orga,
je veux voir les résultats d'un sondage et la liste de ceux qui n'ont pas répondu,
afin d'organiser l'événement et de relancer qui il faut.

## Contexte (cartographie du 2026-11-09)

- Pages admin d'un événement : `frontend/src/app/(admin)/admin/evenements/[eventId]/` (`inscriptions`, `jeux`,
  `pelles`, `partenaires`, `session`, `modifier`). Pas de barre d'onglets : les sous-pages sont des liens par
  ligne dans `frontend/src/features/admin/admin-event-dashboard.tsx` (vers les lignes 425-478).
- Fiche admin d'une inscription : `frontend/src/app/(admin)/admin/evenements/[eventId]/inscriptions/[registrationId]/page.tsx`.

## Décisions

1. **Écran « Sondages » de l'événement** : `/admin/evenements/[eventId]/sondages`, lien ajouté dans
   `admin-event-dashboard.tsx` à côté des autres. Liste des sondages, et pour chacun : création et édition (si le
   front de 51.1 n'a pas été livré), puis résultats.
2. **Résultats** (`GET /api/v1/admin/surveys/{id}/results`) :
   - choix : nombre et pourcentage par option (options retirées comprises, signalées) ;
   - note : moyenne et répartition de 1 à 5 ;
   - texte : la liste des réponses, **triées par ordre alphabétique pour un sondage anonyme** (jamais dans l'ordre
     d'arrivée, qui permettrait de recouper avec les participations) ;
   - nombre de répondants sur la taille du public.
3. **Vue par inscrit** (sondage nominatif seulement) : les réponses de chacun, nom du joueur, lien vers sa fiche
   d'inscription.
4. **Non-répondants** : liste nominative pour tous les sondages (la participation est toujours nominative), avec
   le bouton de relance de 51.3.
5. **Sondage anonyme avec moins de 3 réponses** : les décomptes s'affichent, les réponses texte sont masquées
   (« visibles à partir de 3 réponses »), pour ne pas exposer une réponse identifiable.
6. Fiche admin d'une inscription : bloc « Sondages » avec les réponses nominatives de ce joueur.

## Critères d'acceptation

1. Lien « Sondages » sur chaque événement du tableau de bord admin.
2. Résultats conformes à la décision 2, à jour à chaque ouverture de l'écran.
3. Vue par inscrit absente pour un sondage anonyme, côté API (403 `survey_anonymous`) comme côté écran.
4. Liste des non-répondants avec nom et lien vers la fiche d'inscription.
5. Seuil de 3 réponses pour le texte des sondages anonymes.
6. Bloc « Sondages » sur la fiche d'une inscription (sondages nominatifs seulement).
7. Routes admin uniquement (401 / 403).

## Tests et gates

8. Fonctionnels : agrégats (choix, note, texte), tri alphabétique et seuil en anonyme, non-répondants, 403 de la
   vue par inscrit en anonyme.
9. Front : écran des résultats (types de question, anonyme, vide).
10. `composer gates` et `pnpm gates` verts.

## Tasks / Subtasks

- [ ] **Task 1** (AC 2-5, 8) - Requêtes de résultats, de vue par inscrit et de non-répondants.
- [ ] **Task 2** (AC 1, 2-5, 9) - Écran « Sondages » de l'événement.
- [ ] **Task 3** (AC 6) - Bloc sur la fiche d'inscription.
- [ ] **Task 4** (AC 10) - Gates verts, Dev Agent Record.
