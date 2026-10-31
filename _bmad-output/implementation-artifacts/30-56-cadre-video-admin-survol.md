# Story 30.56: Un cadre vidéo ajouté en admin reste sur son image fixe là où sa vidéo ferait un carré noir

**Status:** review
**Epic:** 30 - Communauté
**Date:** 2026-10-29
**Origine :** Jean le 2026-10-29, en essayant le cadre `neon_runner` uploadé dans `/admin/cadres` : sur `/compte`, au
survol de l'avatar, la vidéo s'affiche dans un carré noir.

## Story

En tant que membre qui porte un cadre vidéo ajouté par un admin,
je veux que mon avatar s'affiche sans carré noir partout où il apparaît,
afin que le cadre rende comme les cadres vidéo intégrés.

## Contexte

- Une vidéo de cadre est de la lumière sur fond noir, posée en `mix-blend-mode: screen`. Sous un parent qui crée un
  contexte d'empilement à fond transparent (le flou d'arrière-plan de `card-glow`), le fondu ne voit rien derrière et
  le noir s'affiche. Ces surfaces passent `hoverVideo={false}` à `MemberAvatar` pour rester sur l'image fixe
  (story 30.47) : la carte de `/compte` (`account-shell.tsx`), les échanges du recap, le badge d'amis d'événement.
- `MemberAvatar` ne reconnaît un cadre vidéo que par le catalogue intégré : `getAvatarFrame(frame)?.variant !== "video"`.
  Un cadre uploadé en admin (story 41.10) n'est pas dans ce catalogue, `getAvatarFrame` rend `null`, il est donc pris
  pour un cadre CSS et sa vidéo se lance au survol malgré `hoverVideo={false}`.
- L'en-tête (`public-shell.tsx`) est un contexte d'empilement (`z-50`, `backdrop-blur`) à fond `bg-background/92`, et
  l'avatar du menu utilisateur y anime son cadre en permanence (`animate="always"`). Le fondu `screen` y rend le fond
  de l'en-tête opaque sous tout le carré de la vidéo : un carré plus sombre que les 8 % de grille qui transparaissent
  autour (mesuré 38 contre 41 en luminance). Visible surtout avec un cadre fin comme `neon_runner`, présent aussi
  pour les cadres vidéo intégrés.
- Les cadres uploadés sont toujours des vidéos (`AvatarFrameDefinition::upload` exige webm, mp4, poster et still).

## Acceptance Criteria

1. Avec `hoverVideo={false}`, un cadre absent du catalogue intégré (cadre uploadé en admin) reste sur son image fixe,
   même survolé.
2. Un cadre CSS intégré continue de s'animer au survol, un cadre vidéo intégré reste sur son image fixe (inchangé).
3. L'en-tête a un fond opaque : un cadre vidéo n'y dessine plus de carré autour de l'avatar.
4. Tests unitaires de la règle ; `pnpm gates` vert.

## Tasks / Subtasks

- [x] `member-avatar.tsx` : règle « le cadre bouge au survol » extraite et testable ; une clé inconnue du catalogue
      intégré est traitée comme un cadre vidéo (AC 1, 2)
- [x] `public-shell.tsx` : en-tête `bg-background` opaque, sans `backdrop-blur` devenu inutile (AC 3)
- [x] Tests (AC 4)
- [x] Gates

## Dev Agent Record

### Completion Notes List

- Réalisation 2026-10-29 : `frameAnimates(frame, { animate, hovered, hoverVideo })` ; seul un cadre intégré non vidéo
  s'anime au survol quand `hoverVideo` est désactivé. Constat en prod sur `/compte` avec `neon_runner`.
