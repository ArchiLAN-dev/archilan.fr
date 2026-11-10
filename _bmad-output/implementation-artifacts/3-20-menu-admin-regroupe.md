# Story 3.20 - Menu de l'admin regroupé en pages à onglets

**Status:** review
**Epic:** 3 - Event Lifecycle Backoffice (`_bmad-output/planning-artifacts/epics/epic-3-event-lifecycle-backoffice.md`)
**Date:** 2026-11-10
**Branche:** `feature/epic-3-story-20-menu-admin-regroupe` (depuis `feature/epic-3-story-19-sidebar-admin-repliable`)
**Dépend de:** 3.19 (même fichier `admin-shell.tsx`). Front seul.

## Story

En tant qu'admin,
je veux un menu d'administration plus court, où les pages proches sont réunies derrière des onglets,
afin de trouver une section sans parcourir 23 entrées.

## Contexte

Retour de Jean le 2026-11-10 : la barre latérale « doit être minimisée », au sens de la quantité d'entrées (le bouton
pour la réduire de la story 3.19 est gardé). Choix de Jean parmi trois maquettes : regrouper en pages à onglets,
23 entrées ramenées à 12. Les onglets sont ceux du site (`components/ui/tabs.ts`, story 3.18).

## Regroupements

| Entrée du menu | Onglets (adresses inchangées)                                                |
|----------------|------------------------------------------------------------------------------|
| Dashboard      | Vue d'ensemble `/admin`, Statistiques `/admin/statistiques`                  |
| Événements     | -                                                                            |
| Publications   | Actualités `/admin/actualites`, Annonces `/admin/annonces`                   |
| Partenaires    | -                                                                            |
| Jeux           | -                                                                            |
| Utilisateurs   | -                                                                            |
| Adhésions      | -                                                                            |
| Récompenses    | Succès, Boutique, Quêtes hebdo, Cadres, Bannières, Titres                    |
| Modération     | Signalements, Contributions                                                  |
| Apworlds       | Catalogue, Santé, Aide Archipelago                                           |
| Sessions       | Runs hebdos `/admin/weekly-runs`, Config `/admin/sessions/config`            |
| Bot Discord    | -                                                                            |

## Décisions

1. Aucune adresse ne change : les pages d'un groupe partagent un layout via un groupe de routes Next
   (`app/(admin)/admin/(recompenses)/...`), qui affiche la barre d'onglets au-dessus de la page.
2. L'entrée du menu mène au premier onglet et s'allume sur n'importe quelle page du groupe (sous-pages comprises).
3. La pastille d'une entrée additionne celles de ses onglets ; chaque onglet porte la sienne.
4. Une seule source pour le menu et les onglets (`features/admin/admin-sections.ts`).

## Critères d'acceptation

1. Le menu (barre complète, rail, mobile) compte 12 entrées.
2. Chaque page regroupée affiche ses onglets, l'onglet courant marqué (`aria-current`), aux adresses d'avant.
3. Les pastilles de Santé, Signalements et Contributions restent visibles (menu et onglets).
4. Tests : correspondance page -> entrée, pastilles cumulées, onglets. Gates front verts.

## Tâches

- [x] `admin-sections.ts` : entrées, onglets, page active, cumul des pastilles.
- [x] `AdminSectionTabs` et layouts des groupes de routes (déplacements `git mv`, adresses identiques).
- [x] Menu de `admin-shell.tsx` branché sur les sections.
- [x] Tests, gates.

## Dev Agent Record

- `features/admin/admin-sections.ts` : `ADMIN_SECTIONS`, `ADMIN_NAV_GROUPS` (12 entrées), `isSectionActive`,
  `sectionBadge`. `features/admin/admin-section-tabs.tsx` : `useAdminBadges` (sorti du shell, mêmes requêtes),
  `AdminSectionTabsView` / `AdminSectionTabs`.
- Groupes de routes `(tableau)`, `(publications)`, `(recompenses)`, `(apworlds)`, `(sessions-hebdo)` et
  `moderation/layout.tsx` ; fichiers déplacés un par un (`next dev` verrouille les dossiers sous Windows).
  Adresses vérifiées en 200 sur le serveur de dev.
- `admin-shell.tsx` lit les sections (barre, rail, mobile) ; nouvelles icônes Récompenses (Gift), Modération
  (Shield), Apworlds (Library), Sessions (Timer).
- Tests : `admin-sections.test.tsx` (8). typecheck, lint, test (1545), build verts.
