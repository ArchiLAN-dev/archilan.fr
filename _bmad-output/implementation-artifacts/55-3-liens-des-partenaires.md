# Story 55.3 - Liens des partenaires

**Status:** ready-for-dev
**Epic:** 55 - Liens suivis (`_bmad-output/planning-artifacts/epics/epic-55-liens-suivis.md`)
**Date:** 2026-11-17
**Dépend de:** 55.1 (55.2 pour afficher les chiffres).

## Story

En tant qu'association,
je veux que chaque visite envoyée à un partenaire depuis notre site soit comptée,
afin de pouvoir leur dire ce qu'on leur apporte.

## Décisions

1. Un lien suivi peut être celui d'un partenaire (`partner_id`, unique). Il est créé avec le partenaire, avec un code
   tiré de son nom (`partenaire-{nom}`, suffixe numérique en cas de collision) ; sa cible suit l'URL du partenaire.
   Supprimer le partenaire désactive son lien (les compteurs restent).
2. Migration de rattrapage : un lien pour chaque partenaire existant.
3. Toutes les cartes partenaires (accueil, `/partenaires`, fiche d'event) pointent vers `/l/{code}`, en `<a>` simple
   (pas de `next/link`, qui pourrait précharger la route et compter un faux passage). L'écran OBS `/o/partenaires`
   n'a pas de lien.
4. La fiche admin d'un partenaire affiche ses passages sur 30 jours et au total.
5. Un lien de partenaire ne se modifie pas depuis `/admin/liens` (cible et état suivent le partenaire).

## Critères d'acceptation

1. Un clic sur une carte partenaire arrive chez le partenaire et compte un passage sur son lien.
2. Changer l'URL d'un partenaire change la cible de son lien ; le code reste.

## Tests et gates

3. Fonctionnels : création, édition, suppression d'un partenaire et son lien ; migration de rattrapage. Front : cartes.
4. `composer gates` et `pnpm gates` verts.

## Tasks / Subtasks

- [ ] **Task 1** - Lien du partenaire (domaine, commande, migration de rattrapage).
- [ ] **Task 2** - Cartes partenaires via `/l/{code}`, chiffres sur la fiche admin.
- [ ] **Task 3** - Gates.
