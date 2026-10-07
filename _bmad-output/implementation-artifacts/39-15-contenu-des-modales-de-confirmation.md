# Story 39.15: Des modales de confirmation qu'on lit d'un coup d'œil

**Status:** review
**Epic:** 39 - Modération synchronisée avec Discord
**Date:** 2026-10-05

## Story

En tant qu'admin ou membre d'ArchiLAN,
je veux une modale de confirmation qui met en avant ce qui compte (l'action, les chiffres, la conséquence),
afin de vérifier ce que je m'apprête à faire sans déchiffrer une phrase en petits caractères.

## Contexte

La 39.14 a fait passer les neuf confirmations du site sur `ConfirmDialog`. Retour de Jean (2026-10-05) sur la
modale de crédit de pelles : « pas terrible niveau UI ». Constat : un titre, une phrase grise qui entasse solde
avant, solde après et motif, deux boutons. Rien ne ressort, aucune icône ne dit la nature de l'action, et le membre
concerné n'est pas nommé.

## Critères d'acceptation

1. `ConfirmDialog` gagne une icône d'en-tête (pastille teintée selon le ton : accent, ou danger) et un emplacement
   de détails sous la description, présenté comme un récapitulatif encadré. Le pied (Annuler / action) est séparé du
   contenu. Sans icône ni détails, la modale reste valide.
2. Pelles (fiche admin) : le titre nomme le membre ; le récapitulatif montre le mouvement en grand (« +1 500 » ou
   « -1 500 » avec l'icône pelle, vert ou rouge), le solde actuel → nouveau solde, le type (or, ou l'événement), et le
   motif « visible par le membre ».
3. Distribution d'un event : récapitulatif en trois chiffres (par membre, membres, total) et le libellé.
4. Poser une prime : récapitulatif avec l'objet et le montant, et les 10 % prélevés au versement.
5. Les autres confirmations reçoivent une icône qui dit l'action (corbeille, arrêt, clé, email, régénération...).
6. Tests et gates verts.

## Tasks / Subtasks

- [x] **Task 1** (AC 1) - `ConfirmDialog` : icône, détails, pied (rendu en portail, vérifié visuellement ; les
  récapitulatifs qu'il encadre sont testés).
- [x] **Task 2** (AC 2-4) - Récapitulatifs pelles, distribution, prime ; tests.
- [x] **Task 3** (AC 5) - Icônes des autres confirmations.
- [x] **Task 4** (AC 6) - Gates et vérification visuelle.

## Notes techniques

- `ConfirmDialog` : props `icon` (LucideIcon) et `children` (récapitulatif) ; `ConfirmFigure` pour un chiffre
  étiqueté. Le bouton de confirmation reprend le montant (« Créditer 1 500 pelles », « Distribuer 45 pelles »).
- Les helpers texte de la 39.14 deviennent des aperçus chiffrés (`adjustmentPreview`, `distributionPreview`) rendus
  par `AdjustmentSummary`, `DistributionSummary`, `BountySummary`.
- Prime : le montant versé au joueur n'est pas affiché, l'arrondi des 10 % appartient au serveur.
- Vérification visuelle sur une page de prévisualisation temporaire (crédit, débit, distribution, prime,
  suppression), non commitée.
