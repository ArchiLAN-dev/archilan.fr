# Story 41.47: Le blind test aux couleurs d'archilan.fr

**Status:** review
**Epic:** 41 - Pelles
**Date:** 2026-10-25
**Dépôt :** `ArchiLAN-dev/archilan-blind-test` (app)
**Origine :** Jean le 2026-10-25 : « J'aimerais que le site du blindtest utilise le même design que notre site
archilan. »

## Story

En tant que membre qui passe du site au blind test,
je veux retrouver le même habillage (couleurs, polices, logo, boutons),
afin de sentir que c'est le même service et pas un outil à part.

## Critères d'acceptation

1. **Jetons** : l'app reprend les couleurs de `frontend/src/app/globals.css` (fond `#0a1629`, surfaces `#111030` et
   `#1a1840`, bordure `#281f52`, accent `#231470` avec texte d'accent `#9580f5`, accent chaud `#e89420`, texte
   `#e8edf4`, texte secondaire `#7a8ba8`, succès `#1abd8c`, danger `#c4220c`) et ses rayons (0,5 rem, cartes 0,7 rem).
2. **Polices** : Inter pour le texte, Space Grotesk pour les titres, auto-hébergées dans l'app (les fichiers latin du
   site), sans appel à Google.
3. **Fond** : la grille de points du site (40 px, couleur de bordure) ; à la souris, les points s'allument autour du
   pointeur comme sur le site. Sur écran tactile ou avec `prefers-reduced-motion`, la grille reste fixe.
4. **En-tête** : logo ArchiLAN et « ArchiLAN », mention « Blind test », lien vers `SITE_PUBLIC_URL` ; favicon du site.
5. **Composants** : cartes (surface, bordure, verre léger comme `.card-glow`), boutons principaux en accent avec halo
   (`.btn-glow`), boutons secondaires bordés, champs sur `surface-2`, liens en texte d'accent, surtitre orange en
   capitales comme les pages du site.
6. Toutes les pages (connexion, jeu, régie, écran de salle, console, refus) passent par ces styles ; plus de couleur
   écrite en dur dans les templates.

## Tâches

- [x] Copier logo, favicon et polices dans `public/`.
- [x] `base.html.twig` : jetons, polices, fond, en-tête, composants ; variable Twig `site_url`.
- [x] Reprendre les templates (classes au lieu de couleurs en ligne, surtitres).
- [x] Vérifier dans Chrome : connexion, régie, écran, console (sur ordinateur ; la largeur téléphone n'a pas pu
      être simulée).

## Dev Agent Record

- App : PR `archilan-blind-test` (branche `feature/41-47-design`). Polices reprises du build du site (fichiers latin
  d'Inter et de Space Grotesk générés par `next/font`), logo et icônes de `frontend/public`.
- La grille animée est le portage de `grid-background.tsx` en JS sans dépendance ; elle s'arrête quand tous les points
  sont refroidis (pas de boucle à vide).
- Les avertissements « Ne pas projeter » gardent seulement leur titre en rouge : le rouge du site (`#c4220c`) se lit
  mal en paragraphe.
- Vérifié dans Chrome : console, connexion, écran de salle, régie.
