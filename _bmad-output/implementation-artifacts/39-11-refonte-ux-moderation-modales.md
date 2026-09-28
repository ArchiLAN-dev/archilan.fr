# Story 39.11: Refonte UX de la modération : modales et listes à plat

**Status:** review
**Epic:** 39 - Modération synchronisée avec Discord
**Date:** 2026-09-28

## Story

En tant que membre du staff d'ArchiLAN,
je veux des écrans de modération lisibles, où chaque action s'ouvre dans une fenêtre dédiée et où les actions
lourdes demandent confirmation,
afin de modérer vite sans me perdre dans des encadrés imbriqués ni bannir quelqu'un d'un clic malheureux.

## Contexte

L'état des lieux (2026-09-28) :

- La liste « À examiner » de l'onglet Signalements empile jusqu'à quatre niveaux d'encadrés : section ambre,
  carte du compte, formulaire de sanction déplié dans la carte, puis ses champs. L'historique se déplie en un
  second encadré au même niveau.
- Deux interfaces pour les mêmes sanctions : une barre de 5 boutons avec formulaire déplié
  (`account-moderation-controls.tsx`, client `warnAccount`/`banAccount`/... qui renvoie un simple booléen et
  n'affiche aucune erreur) et un formulaire toujours visible à liste déroulante sur la fiche admin
  (`admin-user-moderation.tsx`, client `applyModerationAction` qui relaie le message d'erreur). Les deux
  appellent les mêmes routes.
- Aucune confirmation : Bannir, Masquer un commentaire, Approuver une contribution (qui remplace tout le
  tutoriel) partent au premier clic.
- Le projet n'a aucun composant de fenêtre modale partagé : une dizaine d'overlays écrits à la main, alors que
  `radix-ui` est déjà installé (seul `Popover` est utilisé).

Périmètre : le front de la modération (onglets Signalements et Contributions, section Modération de la fiche
admin). Aucune route ni règle serveur ne change. Remplacer les `window.confirm` du reste de l'admin est hors
périmètre (story à part).

## Critères d'acceptation

1. **Socle partagé** dans `components/ui/`, bâti sur Radix : `Dialog` (fenêtre centrée et variante panneau
   latéral) et `ConfirmDialog` (titre, description, bouton de confirmation, ton « danger »). Accessibles :
   focus piégé, fermeture par Échap et par la croix, titre annoncé.
2. **Une seule fenêtre de sanction** (`SanctionDialog`), ouverte depuis l'onglet Signalements et depuis la fiche
   admin : choix de l'action (Avertir, Suspendre, Bannir, Lever, Note interne), date de fin seulement pour une
   suspension, motif obligatoire (« Note » pour une note interne, avec le rappel que le membre n'est pas
   prévenu), bouton de validation au libellé de l'action et rouge pour un bannissement. Le message d'erreur du
   serveur s'affiche dans la fenêtre, qui reste ouverte. Le client en double (`warnAccount`, `banAccount`,
   `suspendAccount`, `liftAccount`) et `account-moderation-controls.tsx` disparaissent.
3. **« À examiner » à plat** : une seule section, une ligne par compte séparée par un trait (pas de carte dans
   la carte) : nom, score, nombre de signalements, bouton « Sanctionner » (fenêtre de sanction), bouton
   « Historique » (panneau latéral avec l'état, l'historique des actions et le lien vers la fiche admin).
4. **Signalements à plat** : les signalements forment une liste séparée par des traits dans un seul conteneur ;
   la note du signaleur et le commentaire signalé sont des citations à filet gauche, sans encadré.
   « Masquer » demande confirmation ; « Restaurer » et « Résoudre » restent directs.
5. **Contributions** : « Rejeter » ouvre une fenêtre avec la raison obligatoire (envoyée à l'auteur) ;
   « Approuver » demande confirmation en rappelant qu'il remplace l'intégralité du tutoriel.
6. **Fiche admin** : la section Modération montre l'état, une barre d'actions (« Sanctionner », « Note interne »,
   « Répondre au membre » quand un dossier ou une action existe), puis les messages du dossier et l'historique
   en listes à plat. Les formulaires toujours visibles disparaissent au profit des fenêtres. Les garde-fous
   restent : pas d'actions sur un admin ni sur soi-même (message à la place de la barre).
7. `pnpm gates` passe.

## Tasks / Subtasks

- [x] **Task 1** (AC 1) - `components/ui/dialog.tsx` et `components/ui/confirm-dialog.tsx` sur Radix.
- [x] **Task 2** (AC 2) - `sanction-dialog.tsx` : règles de saisie pures testées, fenêtre, erreurs serveur ;
      suppression du client en double et de `account-moderation-controls.tsx`.
- [x] **Task 3** (AC 3, 4) - Onglet Signalements : comptes et signalements à plat, panneau d'historique,
      confirmation du masquage.
- [x] **Task 4** (AC 5) - Onglet Contributions : fenêtres de refus et de confirmation.
- [x] **Task 5** (AC 6) - Fiche admin : barre d'actions, fenêtres de sanction et de réponse, listes à plat.
- [x] **Task 6** (AC 7) - Gates (vérification visuelle en local laissée à la revue, voir ci-dessous).

## Dev Agent Record

- **Socle** `components/ui/` : `Dialog` (Radix Dialog, variantes `center` et `side`, `DialogBody`, `DialogFooter`),
  `ConfirmDialog` (Radix AlertDialog, ton `danger`, le clic à l'extérieur ne ferme pas) et `buttonVariants`
  (cva : `primary`, `danger`, `secondary`, `ghost`). Le contenu d'une fenêtre est démonté à la fermeture : un
  formulaire repart vide à chaque ouverture.
- **Sanction** : `sanction-rules.ts` (actions, libellés, `canSubmitSanction`, `sanctionUntil`) et
  `sanction-dialog.tsx` (`SanctionDialog` + `SanctionForm`), sur `applyModerationAction` qui relaie les refus du
  serveur. Supprimés : `account-moderation-controls.tsx`, `warnAccount`/`suspendAccount`/`banAccount`/
  `liftAccount`/`fetchAccountActions` et leur test (les routes serveur ne changent pas).
- **Historique partagé** `moderation-history.tsx` : bandeau d'état, liste des actions, messages du dossier, en
  listes à plat (`divide-y`), repris par la fiche admin et par le panneau latéral des comptes à examiner.
- **Signalements** : `flagged-accounts.tsx` (une ligne par compte, « Historique » en panneau latéral avec lien
  vers la fiche, « Sanctionner »), signalements en liste à plat, citations à filet gauche, masquage confirmé.
- **Contributions** : approbation confirmée (rappel du remplacement intégral), refus dans une fenêtre avec
  raison obligatoire.
- **Fiche admin** : barre « Sanctionner » / « Note interne » / « Répondre au membre », fenêtres de sanction et de
  réponse, messages et historique à plat.

### Déroulé TDD

| Étape | Rouge | Vert |
|---|---|---|
| `sanction-rules.test.ts`, `sanction-dialog.test.tsx`, `flagged-accounts.test.tsx` | modules absents | 12 verts |

Gates : `pnpm gates` vert (589 tests, build). `pnpm lint` : 0 erreur ; les 10 avertissements
`no-location-assign-relative-destination` viennent de fichiers hors périmètre, déjà sur develop.

### Vérification visuelle

Pas faite en local : l'API partagée n'accepte qu'une origine (`FRONTEND_ORIGIN=http://localhost:3000`), occupée
par le serveur de dev du tree principal. À faire en revue sur `/admin/moderation` et une fiche
`/admin/utilisateurs/{id}`.
