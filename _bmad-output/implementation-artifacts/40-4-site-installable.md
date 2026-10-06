# Story 40.4: Le site s'installe comme une application (push sur iPhone)

**Status:** review
**Epic:** 40 - Sortie de BK et notifications push
**Date:** 2026-10-06

## Story

En tant que membre sur iPhone ou iPad,
je veux installer ArchiLAN sur mon écran d'accueil,
afin de recevoir les notifications push (sortie de BK), qu'iOS réserve aux sites installés.

## Contexte

Story 40.2 : le Web Push fonctionne sur ordinateur et Android ; iOS (16.4+) ne le permet qu'à un site ouvert depuis
l'écran d'accueil, ce qui demande un manifeste d'application web. La carte « Notifications sur cet appareil »
reconnaissait déjà le cas (`ios-install`) mais disait « pas encore disponibles ». Piste proposée par Claude et
retenue par Jean (2026-10-06, « Go faire les stories que tu m'as donné »).

## Critères d'acceptation

1. Manifeste (`/manifest.webmanifest`) : nom, `display: standalone`, démarrage sur `/`, couleurs du site, icônes
   PNG 192 et 512 et une icône « maskable ».
2. `apple-touch-icon` en PNG (iOS ignore le WebP), couleur de thème, mode application web.
3. La carte des notifications sur iPhone explique comment installer le site (Partager, « Sur l'écran d'accueil »)
   puis revenir activer les notifications.
4. `pnpm gates` vert ; manifeste vérifié sur le serveur de dev.

## Dev Agent Record

- Icônes générées depuis `public/images/logo.webp` (sharp) : `public/icons/icon-192.png`, `icon-512.png`,
  `icon-maskable-512.png` et `apple-touch-icon.png` (logo sur le fond du site, marge pour le recadrage).
- `frontend/src/app/manifest.ts` (+ test), `frontend/src/app/layout.tsx` (`viewport.themeColor`, `appleWebApp`,
  icône Apple), `frontend/src/features/auth/push-notifications-card.tsx` (+ test).
- Non testé sur un vrai iPhone : à vérifier après déploiement (installer depuis Safari, ouvrir depuis l'icône,
  activer les notifications dans Mon compte > Notifications).
