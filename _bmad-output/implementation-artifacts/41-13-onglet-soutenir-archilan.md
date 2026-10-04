# Story 41.13: Onglet « Soutenir ArchiLAN » : adhésion, don et articles

**Status:** review
**Epic:** 41 - Les Pelles, monnaie virtuelle d'ArchiLAN
**Date:** 2026-10-05

## Story

En tant que visiteur ou membre,
je veux trouver au même endroit tout ce qui aide l'association (adhérer, donner, acheter un article),
afin de la soutenir sans chercher.

## Contexte

La 41.12 a réuni les deux boutiques sous `/boutique` : « Cosmétiques » (pelles) et « Articles ArchiLAN »
(formulaire boutique HelloAsso). L'adhésion vit à part sur `/adhesion` ; aucun parcours de don n'existe. Décisions de
Jean (2026-10-05) : l'onglet devient « Soutenir ArchiLAN » et regroupe adhésion, don et articles ; `/adhesion`
redirige vers lui ; un formulaire de don HelloAsso existe, son slug sera renseigné dans la configuration.

## Critères d'acceptation

1. Configuration `HELLOASSO_DONATION_FORM_SLUG` (vide par défaut, comme l'adhésion et la boutique). Endpoint public
   `GET /api/v1/donation/checkout` : `{data: {checkoutEmbedUrl}}`, `null` sans slug. L'URL suit le chemin HelloAsso
   des formulaires de don (`formulaires`).
2. Une commande de don reçue par webhook est synchronisée comme les autres (type API `Donation`), sans effet sur les
   adhésions ni les inscriptions.
3. `/boutique?onglet=asso` devient l'onglet « Soutenir ArchiLAN » : trois sections ancrées, dans l'ordre Adhésion
   (`#adhesion`), Faire un don (`#don`), Articles ArchiLAN (`#articles`), chacune avec son formulaire HelloAsso derrière
   l'acceptation des conditions, ou un message « indisponible » quand il n'est pas configuré. Un sommaire en tête mène
   à chaque section.
4. `/adhesion` redirige vers `/boutique?onglet=asso#adhesion` ; les liens internes vers `/adhesion` pointent
   directement vers l'onglet.
5. Tests et gates verts.

## Tasks / Subtasks

- [x] **Task 1** (AC 1-2) - `DonationCheckout`, contrôleur, correspondance du type `Donation`, configuration ; tests.
- [x] **Task 2** (AC 3-4) - Onglet, sections, redirection, liens ; tests.
- [x] **Task 3** (AC 5) - Gates.

## Notes techniques

- Les dons apparaissent dans le chiffre d'affaires total des statistiques (42.3) mais pas encore dans le détail par
  type (événements, adhésions, boutique).
