# Story 36.8: La fiche utilisateur en onglets

**Status:** review
**Epic:** 36 - Fiche utilisateur admin
**Date:** 2026-10-05

## Story

En tant qu'admin d'ArchiLAN,
je veux une fiche utilisateur rangée en quelques onglets, qui reste sur l'onglet ouvert quand je recharge,
afin de trouver une information sans faire défiler huit sections empilées.

## Contexte

La 36.7 a mis la fiche en page : huit sections empilées (Identité, Accès et rôles, Modération, Adhésion,
Inscriptions, Jeu, Pelles, Journal) sous un sommaire d'ancres. Retour de Jean (2026-10-05) : la page est trop
chargée. Décision : cinq onglets, l'onglet actif dans l'adresse.

| Onglet | Sections |
|---|---|
| Compte | Identité, Accès et rôles |
| Modération | Modération |
| Association | Adhésion, Inscriptions |
| Jeu et pelles | Pelles, Jeu |
| Journal | Journal d'activité |

## Critères d'acceptation

1. Sous l'en-tête de la fiche (avatar, nom, badges), une barre d'onglets remplace le sommaire d'ancres ; seul
   l'onglet actif est affiché. Barre accessible (`tablist` / `tab` / `tabpanel`, flèches gauche/droite), défilante
   sur téléphone, au style des autres onglets de l'admin.
2. L'onglet actif est dans l'adresse (`?onglet=moderation`) : un rechargement ou un lien partagé rouvre le même
   onglet. Sans paramètre, ou avec une valeur inconnue : « Compte ». Changer d'onglet remplace l'adresse sans
   ajouter d'entrée d'historique ni faire défiler la page.
3. Un ancien lien vers une section (`#pelles`, `#adhesion`...) ouvre l'onglet qui la contient.
4. Aucune section ne change de contenu ; aucune route serveur ne change.
5. Tests et gates verts.

## Tasks / Subtasks

- [x] **Task 1** (AC 1-3) - Onglets de la fiche : définition, résolution depuis l'adresse, barre ; tests.
- [x] **Task 2** (AC 1, 4) - Fiche : panneaux par onglet, `Suspense` pour `useSearchParams`.
- [x] **Task 3** (AC 5) - Gates et vérification visuelle.

## Notes techniques

- `admin-sheet-section.tsx` : `SHEET_TABS` (chaque section dans un seul onglet, ordre de la page, vérifié par test),
  `resolveSheetTab(param, hash)` (pure), `SheetTabs`, `SheetTabPanel`. `SheetNav` (sommaire d'ancres) disparaît.
- `use-sheet-tab.ts` : l'onglet vit dans `?onglet=`, changé par `router.replace` (`scroll: false`). Le hash n'existe
  que côté navigateur : il est lu après montage (pas d'écart d'hydratation), converti en onglet, puis la section
  visée défile en vue une fois la fiche chargée.
- Seul l'onglet actif est monté : ses requêtes partent à l'ouverture, le cache TanStack évite de recharger au retour.
- La première section d'un onglet perd son filet du haut (`first:border-t-0`), la barre d'onglets en tient lieu.
- Vérification dans le navigateur sur une page temporaire (vraie barre, vrai hook, panneaux factices) : clic,
  adresse remplacée, rechargement, ancien lien `#pelles` -> `?onglet=jeu#pelles`, flèches. Non commitée.
- Retour de Jean (2026-10-05) après livraison : dans « Jeu et pelles », Pelles passe avant Jeu.
