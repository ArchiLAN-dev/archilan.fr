# Story 55.2 - Statistiques des liens

**Status:** ready-for-dev
**Epic:** 55 - Liens suivis (`_bmad-output/planning-artifacts/epics/epic-55-liens-suivis.md`)
**Date:** 2026-11-17
**Dépend de:** 55.1.

## Story

En tant qu'admin,
je veux voir combien de passages chaque lien suivi a reçus et d'où ils venaient,
afin de savoir quels canaux amènent du monde et ce qu'on envoie à nos partenaires.

## Décisions

1. Sur `/admin/liens` : par lien, total, 7 et 30 derniers jours (jour de Paris), filtre par sens (entrants,
   sortants, tous), tri par passages sur 30 jours.
2. Détail d'un lien : courbe par jour sur 90 jours (jours sans passage à zéro), domaines d'origine principaux
   (« direct » pour le vide), sur 90 jours.
3. Mention visible : « Des passages, pas des visiteurs uniques : sans cookie ni IP, deux clics de la même personne
   comptent deux fois. Les aperçus Discord et les robots ne comptent pas. »

## Critères d'acceptation

1. Les chiffres 7 j, 30 j et total d'un lien sont justes, jour de Paris compris.
2. La courbe montre 90 jours, trous compris ; les origines sont triées par passages.

## Tests et gates

3. Fonctionnels sur la requête de stats (bornes de jours, origines). Front : tableau, filtre, courbe.
4. `composer gates` et `pnpm gates` verts.

## Tasks / Subtasks

- [ ] **Task 1** - Requête DBAL des stats et endpoint admin.
- [ ] **Task 2** - Colonnes et filtre sur `/admin/liens`, détail d'un lien (courbe recharts).
- [ ] **Task 3** - Gates.
