# Story 55.3 - Liens des partenaires

**Status:** review
**Epic:** 55 - Liens suivis (`_bmad-output/planning-artifacts/epics/epic-55-liens-suivis.md`)
**Date:** 2026-11-17
**Dépend de:** 55.1 (55.2 pour afficher les chiffres).

## Story

En tant qu'association,
je veux que chaque visite envoyée à un partenaire depuis notre site soit comptée,
afin de pouvoir leur dire ce qu'on leur apporte.

## Décisions

1. Un lien suivi peut être celui d'un partenaire (`partner_id`, unique). Il est créé avec le partenaire, avec un code
   tiré de son nom (`partenaire-{nom}`, suffixe numérique en cas de collision) ; sa cible suit l'URL du partenaire.
   Supprimer le partenaire désactive son lien (les compteurs restent).
2. Migration de rattrapage : un lien pour chaque partenaire existant.
3. Toutes les cartes partenaires (accueil, `/partenaires`, fiche d'event) pointent vers `/l/{code}`, en `<a>` simple
   (pas de `next/link`, qui pourrait précharger la route et compter un faux passage). L'écran OBS `/o/partenaires`
   n'a pas de lien.
4. La fiche admin d'un partenaire affiche ses passages sur 30 jours et au total.
5. Un lien de partenaire ne se modifie pas depuis `/admin/liens` (cible et état suivent le partenaire).

## Critères d'acceptation

1. Un clic sur une carte partenaire arrive chez le partenaire et compte un passage sur son lien.
2. Changer l'URL d'un partenaire change la cible de son lien ; le code reste.

## Tests et gates

3. Fonctionnels : création, édition, suppression d'un partenaire et son lien ; migration de rattrapage. Front : cartes.
4. `composer gates` et `pnpm gates` verts.

## Tasks / Subtasks

- [x] **Task 1** - Lien du partenaire (domaine, commande, migration de rattrapage).
- [x] **Task 2** - Cartes partenaires via `/l/{code}`, chiffres sur la fiche admin.
- [x] **Task 3** - Gates.

## Dev Agent Record

### Implémentation

- Domaine : `TrackedLink` porte `partner_id` (unique, nullable, sans clé étrangère : le lien survit au partenaire),
  `forPartner()` et `followPartner()` ; libellé « Partenaire : {nom} ». `findByPartner()` sur le dépôt des liens ;
  `PartnerRepositoryInterface::save()` et `remove()` prennent le lien en option, enregistré dans le même flush.
- `ManagePartners` : création et modification font suivre le lien (nom, adresse, état « affiché ») ; un partenaire
  sans lien en reçoit un ; la suppression désactive le lien, les compteurs restent. Code par
  `Application/Support/PartnerLinkCode` (`partenaire-{nom}` en ASCII, `-2`, `-3`... si pris, 40 caractères au plus,
  `partenaire` seul pour un nom sans lettre).
- `ManageTrackedLinks::edit()` refuse un lien de partenaire (409 `tracked_link_follows_partner`) ; la liste admin des
  liens porte `partnerId`.
- `PartnerCatalogQuery` : `linkPath` (`/l/{code}`, null si le lien est éteint) côté visiteurs et admin, `visits` côté
  admin (via `TrackedLinkCatalogQuery::visitsByLink()`).
- Migration `Version20261117140000` : colonne, index unique, rattrapage en `postUp` (règle de code réécrite dans la
  migration, idempotente). PHPStan scanne `migrations/` pour que le test puisse la charger.
- Front : `partnerHref()` (lien suivi, sinon l'URL : anciens payloads et lien éteint), cartes de l'accueil, de
  `/partenaires`, de la fiche d'event et du récap en `<a>` simple ; l'écran OBS n'a pas de lien. Fiche admin du
  partenaire : passages sur 30 jours et au total. `/admin/liens` : pastille « Partenaire », pas de bouton Modifier.

### Vérifications

- `PartnerLinkTest` (création, homonymes, modification, masquage, passage compté, refus d'édition, suppression,
  rattrapage de migration avec un code déjà pris et un nom sans lettre, deux passes) ; `PartnerLinkCodeTest`.
- Jest : `partnerHref`, garde de type, carte, compteurs admin, lien de partenaire non modifiable.
- Migration jouée sur la base de dev : 4 partenaires, 4 liens.
- Cartes sans `noreferrer` (`sponsored noopener`) : le passage est compté avec l'origine `archilan.fr` et le
  partenaire voit la visite venir d'ArchiLAN dans ses propres statistiques. La politique du site
  (`strict-origin-when-cross-origin`) n'envoie que l'origine, jamais la page. Vérifié en réel : origine enregistrée.
