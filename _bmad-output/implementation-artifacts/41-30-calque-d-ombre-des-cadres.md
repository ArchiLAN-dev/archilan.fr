# Story 41.30: Calque d'ombre des cadres vidéo

**Status:** review
**Epic:** 41 - Les Pelles, monnaie virtuelle d'ArchiLAN
**Date:** 2026-10-07

## Story

En tant qu'admin,
je veux pouvoir joindre à un cadre vidéo un calque d'ombre,
afin que ses formes (les mains de l'Envie) passent devant la photo au lieu de s'y fondre.

## Contexte

Les cadres vidéo (30.46, 41.10) sont de la lumière filmée sur fond noir, posée en `mix-blend-mode: screen` : ce mode ne
fait qu'éclaircir, il ne peut pas masquer la photo. Le cadre « Mains de l'Envie » (mains noires qui agrippent l'avatar)
demande d'assombrir : une seconde vidéo, noir sur blanc, posée en `multiply` sous la lumière (le blanc ne change rien,
le noir assombrit). Demandé par Jean le 2026-10-07 après l'aperçu HTML des deux rangées (lumière + ombre, lumière
seule).

Une seule vidéo ne suffit pas : un élément n'a qu'un mode de fusion, et une vidéo avec canal alpha (VP9 alpha) n'est
pas lue par Safari, qui retombe sur le MP4 H.264 sans transparence.

## Critères d'acceptation

1. **API** : un cadre téléversé porte une ombre optionnelle (WebM + MP4, les deux ou aucune), donnée au téléversement
   ou plus tard (`POST /api/v1/admin/avatar-frames/{key}/shade`), retirée par `DELETE` sur la même route. Refus pour un
   cadre intégré (ses fichiers sont dans le code). Le catalogue public et l'admin exposent `video.shade` (null sans
   ombre).
2. **Affichage** : quand le cadre joue, l'ombre est posée en `multiply` sous la lumière (`screen`), même géométrie, et
   suit l'horloge de la lumière. Sans animation, l'image fixe seule (elle porte déjà les formes sombres).
3. **Admin** : champs d'ombre optionnels au téléversement, et sur chaque cadre téléversé : ajouter, remplacer ou
   retirer l'ombre ; mention « ombre » dans la liste.
4. Gates verts ; tests.

## Tasks / Subtasks

- [x] **Task 1** (AC 1) - API : colonnes `shade_webm_key` / `shade_mp4_key`, `AvatarFrameDefinition::shadeWith()`,
  `ManageAvatarFrames::shade()` / `removeShade()`, routes, catalogue ; migration ; tests.
- [x] **Task 2** (AC 2) - Front : `AvatarFrameVideo.shade`, garde du catalogue, `videoLayer` à deux vidéos ; tests.
- [x] **Task 3** (AC 3) - Admin des cadres ; tests.
- [x] **Task 4** (AC 4) - Gates.

## Dev Agent Record

- API : `AvatarFrameFileRule::SHADE_ROLES` (mêmes contrôles que les vidéos de lumière), fichiers
  `avatar-frames/{key}-shade-{stamp}.webm|.mp4`, migration `Version20261007160000`.
- Front : `followLight()` remet l'ombre sur le temps de la lumière (sa sœur suivante) au-delà de 80 ms d'écart, par
  `requestAnimationFrame`, nettoyé par le retour du ref callback (React 19).
- Admin : `ShadeControl`, `FileInputs` partagé avec le formulaire d'ajout ; la mention « Pas de visuel généré par IA »
  du formulaire est retirée (les cadres sont générés pour l'instant).
- Hors story, demandé le même jour : l'avatar du menu déroulant du compte est animé comme celui de la barre
  (`animate="always"`).
- Gates : `composer gates` (2 808) et `pnpm gates` verts.
