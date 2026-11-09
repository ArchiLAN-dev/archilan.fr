# Story 52.5 - Ce que le Passeur a ouvert

**Status:** ready-for-dev
**Epic:** 52 - Le Passeur (`_bmad-output/planning-artifacts/epics/epic-52-passeur.md`)
**Date:** 2026-11-09
**Branche:** `feature/epic-52-story-5-ce-que-le-passeur-a-ouvert` (depuis `develop`)
**Dépend de:** 52.4 (table `passeur_opening`).

## Story

En tant que joueur absent,
je veux voir ce que le Passeur a ouvert dans mon monde et ce que ça m'a rapporté,
afin de ne pas être surpris en reprenant ma partie.

En tant qu'orga,
je veux un journal de toutes les ouvertures,
afin de surveiller l'usage du Passeur et d'ajuster ses réglages.

## Décisions

1. **Côté absent** : un encart sur sa page de partie tant qu'il ne l'a pas fermé, « Pendant ton absence, le Passeur
   a ouvert N emplacements de ton monde », avec la liste (emplacement, pour qui, quand) et le total de pelles
   reçues. Fermer l'encart le masque jusqu'à la prochaine ouverture.
2. Les notifications envoyées à chaque ouverture (52.4) renvoient vers cet encart.
3. **Journal admin** : sur l'événement (tableau de bord) et sur une session, toutes les ouvertures, succès et échecs,
   avec acheteur, absent, emplacement, objet, prix payé, part versée, statut. Le propriétaire d'une partie privée
   voit le journal de sa partie.
4. **Récap de partie** (epic 32) : les ouvertures apparaissent dans le fil, marquées « Passeur ».

## Critères d'acceptation

1. L'absent voit l'encart avec la liste et le total ; il le ferme ; une nouvelle ouverture le fait réapparaître.
2. Un autre joueur ne voit pas l'encart d'un absent (seulement les messages publics du feed).
3. Journal admin de l'événement et de la session, journal du propriétaire en partie privée, avec les droits
   correspondants.
4. Le récap de partie marque les objets reçus par le Passeur.

## Tests et gates

5. Fonctionnels : encart (contenu, fermeture, réapparition), droits du journal.
6. Front : encart, journal, marque dans le récap.
7. `composer gates` et `pnpm gates` verts.

## Tasks / Subtasks

- [ ] **Task 1** (AC 1, 2) - Requête et encart de l'absent, état « vu ».
- [ ] **Task 2** (AC 3) - Journaux admin et propriétaire.
- [ ] **Task 3** (AC 4) - Marque dans le récap de partie.
- [ ] **Task 4** (AC 7) - Gates verts, Dev Agent Record.
