# Story 33.27: Corrections front transverses - échecs qui collent, navigations, modales de la run

**Status:** review
**Epic:** 33 - Cleanup
**Date:** 2026-10-05

## Story

En tant que membre ou admin d'ArchiLAN,
je veux qu'une page se rétablisse d'elle-même après une panne passagère de l'API, et que les confirmations de la
page d'une run se comportent comme toutes les autres,
afin de ne pas devoir recharger le site pour sortir d'une erreur.

## Contexte

Relevé pendant les stories 36.9 et 16.21 (2026-10-05), hors de leur périmètre :

1. **Un échec reste affiché.** Les fonctions `fetch*` renvoient `null` en cas d'échec (AC-API2) au lieu de lever :
   TanStack Query range ce `null` comme un succès et le garde pendant le `staleTime`. Constaté sur la fiche admin :
   « Impossible de charger l'activité de jeu » est resté affiché, API revenue, jusqu'à un rechargement complet.
2. **10 avertissements ESLint** `no-location-assign-relative-destination` (`window.location.href` vers une page
   interne, 12 occurrences en tout), présents sur `develop`. `frontend/AGENTS.md` exige 0 avertissement mais
   `pnpm lint` ne le vérifie pas.
3. **Quatre modales maison** sur la page d'une run (arrêter, terminer, annuler la partie, supprimer) : overlays écrits
   à la main, sans focus piégé ni fermeture par Échap, quand le reste du site passe par `ConfirmDialog`
   (39.11, 39.14, 39.15).

## Critères d'acceptation

1. Une requête dont la donnée en cache vaut `null` (l'échec encodé par AC-API2) est refaite au prochain montage et au
   retour sur l'onglet, quel que soit son `staleTime` ; une requête qui a réussi garde son comportement. Réglage
   unique dans `makeQueryClient`, testé.
2. Les navigations internes passent par le routeur Next (`router.push` / `router.replace`) ; `pnpm lint` échoue au
   moindre avertissement (`--max-warnings 0`) et passe.
3. Les modales « Arrêter », « Terminer », « Annuler la partie » et « Supprimer » de la page d'une run passent sur
   `ConfirmDialog`, textes et effets inchangés.
4. Tests et gates verts.

## Tasks / Subtasks

- [x] **Task 1** (AC 1) - `refetchOnMount` / `refetchOnWindowFocus` sur donnée `null` ; test.
- [x] **Task 2** (AC 2) - Navigations internes, `--max-warnings 0`.
- [x] **Task 3** (AC 3) - Modales de la page d'une run.
- [x] **Task 4** (AC 4) - Gates et vérification dans l'app.

## Notes techniques

- `lib/query-client.ts` : `refetchWhenFailed` en `refetchOnMount` / `refetchOnWindowFocus` par défaut (aucune requête
  ne surcharge `refetchOnMount` ; trois surchargent `refetchOnWindowFocus: false` et le gardent). Une lecture dont
  le `null` veut dire « rien » coûte une requête par montage. Test de comportement avec un vrai `QueryClient`.
- 12 navigations réécrites : `router.push` après une action, `router.replace` pour un renvoi vers la connexion ;
  `router` ajouté aux dépendances des effets. Les trois créations admin (article, jeu ×2) invalident la liste
  avant de naviguer : le rechargement complet qu'elles remplacent rafraîchissait le cache. `pnpm lint` passe à
  `eslint --max-warnings 0`.
- `ConfirmDialog` gagne `cancelLabel` (« Garder la partie » sur l'annulation, où « Annuler » se confondait avec
  l'action). Les quatre modales gardent leurs props et leurs textes.
- Vérifié dans l'app : « Annuler la partie ? » s'ouvre focus sur « Garder la partie », Échap la ferme sans rien
  envoyer.
