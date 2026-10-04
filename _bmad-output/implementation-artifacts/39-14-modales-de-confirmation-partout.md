# Story 39.14: Des modales de confirmation partout, plus aucun popup du navigateur

**Status:** review
**Epic:** 39 - Modération synchronisée avec Discord
**Date:** 2026-10-05

## Story

En tant qu'admin ou membre d'ArchiLAN,
je veux que chaque action importante me demande confirmation dans une vraie fenêtre du site,
afin de relire ce que je m'apprête à faire dans une interface lisible, et non dans le popup gris du navigateur.

## Contexte

La 39.11 a posé `components/ui/confirm-dialog.tsx` (Radix AlertDialog : focus sur « Annuler », pas de fermeture au
clic extérieur, ton `danger`, état `pending`) et laissé hors périmètre « les `window.confirm` du reste de l'admin
(story à part) ». Il en reste neuf. Déclencheur (Jean, 2026-10-05) : la confirmation « Créditer 1 500 pelles ? Solde :
0 pelle → 1 500 pelles. » de `/admin/utilisateurs/<id>#pelles` s'affiche dans le popup natif ; il veut une vraie
modale. Décision : on les remplace tous dans une seule story.

| Écran | Fichier | Action | Ton |
|---|---|---|---|
| Fiche admin, pelles | `wallet/admin-user-pelles.tsx` | Créditer / débiter | default / danger |
| Pelles d'un event | `wallet/admin-event-pelles-page.tsx` | Distribuer | default |
| Primes d'une partie | `wallet/item-bounties.tsx` | Poser une prime | default |
| Primes d'une partie | `wallet/item-bounties.tsx` | Retirer une prime | default |
| Fiche admin, actions | `admin/admin-user-actions.tsx` | Révoquer les sessions / valider l'email | danger / default |
| Fiche admin, parties | `admin/admin-user-gaming.tsx` | Arrêter la partie | danger |
| Bibliothèque de jeux | `admin/admin-game-library-dashboard.tsx` | Supprimer un jeu | danger |
| Éditeur de jeu | `admin/admin-game-editor.tsx` | Régénérer le template | danger |
| Session admin | `admin/admin-session-page.tsx` | Supprimer le container | danger |

Aucune route ni règle serveur ne change.

## Critères d'acceptation

1. Les neuf confirmations ci-dessus s'ouvrent dans `ConfirmDialog` : un titre qui pose la question, une description
   qui reprend les chiffres ou la conséquence, un bouton nommé par l'action (« Créditer », « Supprimer »...), le ton du
   tableau.
2. Pelles (fiche admin) : la modale montre le montant, le solde avant → après, et le motif saisi. Distribution d'un
   event : montant par membre, nombre de membres, total.
3. Pendant l'appel, la modale reste ouverte, son bouton affiche l'état en cours et ne peut pas être recliqué ; elle se
   ferme à la fin, le résultat (succès ou erreur) s'affiche à l'endroit habituel de l'écran. « Annuler » ou Échap
   n'envoient rien.
4. Garde-fou : ESLint interdit `window.confirm` et `confirm` global dans `src/`.
5. Tests et gates verts.

## Tasks / Subtasks

- [x] **Task 1** (AC 1-3) - Pelles : fiche admin, distribution, primes ; helpers de texte et tests.
- [x] **Task 2** (AC 1, 3) - Admin : actions membre, arrêt de partie, jeu, template, container.
- [x] **Task 3** (AC 4) - Règle ESLint.
- [x] **Task 4** (AC 5) - Gates.

## Notes techniques

- Les helpers `adjustmentConfirmation` et `distributionConfirmation` renvoient désormais `{title, description}` ;
  leurs tests suivent.
- Le retrait d'une prime gagne un état « en cours » (il n'en avait pas) : il partage celui de la pose.
- La suppression d'un jeu rattrape l'erreur réseau, sinon la modale resterait bloquée en « en cours ».
- Les 10 avertissements ESLint `no-location-assign-relative-destination` existent déjà sur `develop` : hors périmètre.
