# Story 41.14: Promotions temporaires en boutique

**Status:** review
**Epic:** 41 - Les Pelles, monnaie virtuelle d'ArchiLAN
**Date:** 2026-10-05

## Story

En tant qu'admin d'ArchiLAN,
je veux mettre un article de la boutique en promotion pour une durée limitée,
afin de relancer les ventes à un moment choisi (un événement, une saison) sans changer son prix pour de bon.

En tant que joueur,
je veux voir d'un coup d'œil ce qui est en promotion, de combien et jusqu'à quand,
afin d'en profiter avant la fin.

## Contexte

La boutique (41.7, 41.12) a deux onglets : « Cosmétiques », vendus en pelles d'or par le site, et « Soutenir
ArchiLAN » (41.13) dont les articles passent par un formulaire HelloAsso. Décisions de Jean (2026-10-05) :

- **Les deux boutiques.** Cosmétiques : la promotion est gérée par le site, prix compris. Articles HelloAsso : les
  prix vivent dans HelloAsso, la remise s'y règle ; le site affiche un bandeau « promo en cours » saisi par l'admin.
- **Prix promo par article**, entre une date de début (facultative : tout de suite) et une date de fin
  (obligatoire : une promotion est temporaire). Le prix normal ne change pas.
- **Affichage** : prix barré + prix promo, badge « -X % », compte à rebours, filtre « En promo ».

## Critères d'acceptation

1. Un article de cosmétique porte au plus une promotion : prix promo (au moins 1 pelle, strictement sous le prix
   normal), début facultatif, fin obligatoire et postérieure au début. Migration réversible.
2. Le prix d'un achat est celui **en vigueur à l'instant de l'achat**, calculé par le serveur : prix promo pendant la
   promotion, prix normal avant et après. Le mouvement de pelles mentionne la promotion.
3. Admin (`/admin/boutique`) : poser, modifier et retirer la promotion d'un article ; la liste montre la promotion
   (prix, dates, en cours / à venir / terminée). Changer le prix normal sous le prix promo est refusé.
4. Boutique de cosmétiques : pendant la promotion, la carte et la modale d'achat montrent le prix normal barré, le
   prix promo et un badge « -X % » ; la carte affiche un compte à rebours (« Plus que 2 j », « Plus que 5 h ») ; un
   filtre « En promo » ne garde que les articles en promotion (masqué quand il n'y en a aucune).
5. Articles HelloAsso : l'admin saisit un bandeau (message, date de fin) ; tant que la date n'est pas passée,
   l'onglet « Soutenir ArchiLAN » l'affiche en tête de la section Articles, avec son compte à rebours.
6. Tests et gates verts.

## Tasks / Subtasks

- [x] **Task 1** (AC 1-2) - `ShopItem` : promotion, prix en vigueur ; achat au prix en vigueur ; migration ; tests.
- [x] **Task 2** (AC 3) - `ManageShop` + endpoints admin ; lectures catalogue et admin ; tests.
- [x] **Task 3** (AC 3-4) - Front admin et boutique : formulaire de promotion, prix barré, badge, compte à rebours,
  filtre ; tests.
- [x] **Task 4** (AC 5) - Bandeau HelloAsso : stockage, endpoints public et admin, affichage ; tests.
- [x] **Task 5** (AC 6) - Gates et vérification dans l'app.

## Notes techniques

- `ShopItem` : `promote` / `endPromotion` / `isOnPromotion` / `priceAt` ; `edit` refuse un prix normal tombant au prix promo.
  `BuyShopItem` débite `priceAt($now)` - le prix en vigueur à l'instant de l'achat, jamais celui que la page affichait -
  et le libellé du mouvement mentionne la promotion.
- Catalogue : `price` = prix en vigueur, `regularPrice`, `promotion` {price, endsAt, percent} | null. Admin :
  `promotion` {price, startsAt, endsAt, status running|upcoming|ended} | null. Routes
  `PUT|DELETE /api/v1/admin/shop/items/{id}/promotion`.
- Bandeau HelloAsso : entité `ShopAnnouncement` (Payments, une ligne), `GET /api/v1/shop/announcement` (public, null hors
  période), `GET|PUT|DELETE /api/v1/admin/shop/announcement`.
- Migration `Version20261005140000` (3 colonnes `shop_item`, table `shop_announcement`), jouée sur la base de dev de Jean
  pendant le développement.
- Front : `timeLeftLabel(endsAt, now)` pur ; le compte à rebours de la boutique part de `now` (prop existante), celui du
  bandeau de `dataUpdatedAt` (AC-HK3).
- Vérifié dans l'app : promotion posée sur « Feu » (1 000 → 750, -25 %), carte et filtre côté membre, bandeau sur
  l'onglet Soutenir ; les deux retirés ensuite. L'achat au prix promo est couvert par le test fonctionnel.
- Revue de code (2026-10-05), trois corrections :
  1. L'achat envoie le prix affiché (`expectedPrice`) ; le serveur refuse en 409 `price_changed` s'il ne correspond
     plus au prix en vigueur (promotion terminée entre-temps, page périmée) au lieu de débiter un autre prix sans
     prévenir. La boutique se recharge après le refus.
  2. Une promotion terminée ne bloque plus la baisse du prix normal (garde seulement si elle est en cours ou à venir).
  3. Une promotion dont la fin est déjà passée est refusée (422), comme le bandeau.
  `ShopItem::edit` et `promote` reçoivent l'instant présent.
