# Story 53.4 - Infos pratiques de l'événement

**Status:** ready-for-dev
**Epic:** 53 - Mon event, l'espace de l'inscrit (`_bmad-output/planning-artifacts/epics/epic-53-espace-inscrit.md`)
**Date:** 2026-11-11
**Branche:** `feature/epic-53-story-4-infos-pratiques` (depuis `develop`)
**Dépend de:** 53.1.

## Story

En tant qu'orga,
je veux écrire pour chaque événement les infos pratiques réservées aux inscrits (quoi apporter, accès, wifi,
repas, contacts du staff, questions fréquentes),
afin que les inscrits les trouvent dans leur espace au lieu de nous les demander.

## Contexte (cartographie du 2026-11-11)

- `Event` n'a qu'un texte libre, `description` (markdown, public, indexé). `venue` est une chaîne seule.
- Édition admin : `features/admin/admin-event-form.tsx` (état de la description l. 83, `MarkdownEditor` de
  `components/markdown/markdown-editor.tsx:37`), page `/admin/evenements/{id}/modifier`.
- Rendu : `Markdown` (`components/markdown/markdown.tsx:110`).
- Dernière migration au 2026-11-11 : `Version20261110100000`. Prendre le numéro suivant le dernier présent sur
  `develop` au moment du commit.

## Décisions

1. **Champ** `Event::practicalInfo` (texte markdown, nullable, 0 à 10 000 caractères, `event_practical_info_too_long`).
2. **Jamais public** : absent du payload public de l'événement, de l'ISR, du JSON-LD, du sitemap.
3. **Lecture** `GET /api/v1/events/{eventId}/practical-info` → `{data: {markdown, updatedAt}}`, réservée aux membres
   ayant une inscription active sur l'événement, et aux admins. Sinon 403 ; visiteur 401 ; texte vide → `markdown`
   à `null`.
4. **Écriture** : avec le reste de l'événement dans le formulaire admin (champ dans l'API admin de modification).
   Le formulaire range le champ dans un bloc « Infos pratiques (inscrits seulement) » avec une aide : « visible des
   seuls inscrits, tu peux y mettre le wifi ou un numéro de téléphone ». Un modèle vide est proposé au premier clic :
   titres `## Accès`, `## À apporter`, `## Sur place`, `## Contacts`, `## Questions fréquentes`.
5. **Espace** : section « Infos pratiques » rendue avec `Markdown`, absente si le texte est vide.
6. Pas d'historique ni de « mis à jour récemment » dans cette story (la date `updatedAt` est affichée discrètement).

## Critères d'acceptation

1. L'admin écrit et enregistre les infos pratiques d'un événement.
2. Un inscrit les voit dans son espace ; un membre non inscrit reçoit 403 sur l'endpoint ; elles n'apparaissent
   nulle part sur la page publique ni dans son HTML.
3. Texte vide : pas de section.
4. Au-delà de 10 000 caractères : refus 422 avec le message.

## Tests et gates

5. Unitaires : borne de longueur.
6. Fonctionnels : modification admin, lecture inscrit / non-inscrit / visiteur / admin, absence dans la réponse
   publique de l'événement.
7. Front : section, formulaire admin.
8. `composer gates` et `pnpm gates` verts.

## Tasks / Subtasks

- [ ] **Task 1** (AC 4, 5) - Champ, migration réversible, validation.
- [ ] **Task 2** (AC 1, 2, 6) - API admin et endpoint de lecture.
- [ ] **Task 3** (AC 1, 3, 7) - Formulaire admin, section de l'espace.
- [ ] **Task 4** (AC 8) - Gates, vérification réelle.

## Dev Agent Record
