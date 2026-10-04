# Story 41.12: Une boutique qui donne envie

**Status:** in-progress
**Epic:** 41 - Les Pelles, monnaie virtuelle d'ArchiLAN
**Date:** 2026-10-04

## Story

En tant que membre,
je veux trouver la boutique facilement, voir ce que j'achète sur mon propre profil avant de payer, et savoir ce que
je peux me permettre,
afin de dépenser mes pelles avec plaisir.

En tant qu'admin,
je veux gérer les articles en voyant ce que je vends et ce qui se vend, les modifier, les mettre en pause ou les
supprimer pour de bon.

## Contexte

La boutique en pelles (41.7) vit sous `/compte/boutique`, reliée seulement depuis la barre latérale du compte. Ses
cartes n'affichent que du texte (la clé brute pour un cosmétique ajouté depuis l'admin en 41.10 et 41.11), l'achat
passe par un `window.confirm`. La page admin est une liste texte où « Retirer » ne fait que poser `retired_at`.
`/boutique` est la boutique HelloAsso de l'association (euros). Décisions de Jean (2026-10-04) : un seul lien
« Boutique » dans l'en-tête avec le solde de pelles, une seule page `/boutique` en deux onglets, aperçu sur le profil
du membre, suppression réelle d'un article.

## Critères d'acceptation

### Accès

1. En-tête du site (bureau et mobile) : lien « Boutique » vers `/boutique`, suivi du solde de pelles en or pour un
   membre connecté.
2. `/compte/boutique` redirige vers `/boutique` ; le lien « Boutique » du compte, le portefeuille (« Dépenser mes
   pelles ») et l'éditeur de profil (cadres et bannières « En boutique ») mènent à `/boutique`.

### Page `/boutique`

3. Deux onglets : « Cosmétiques » (par défaut) et « Articles ArchiLAN » (la boutique HelloAsso, inchangée, toujours
   publique et indexée). L'onglet se garde dans l'adresse (`?onglet=asso`).
4. Cosmétiques : l'en-tête montre le solde du membre et un lien vers l'historique (portefeuille). Chaque carte montre
   l'article lui-même : le cadre animé sur l'avatar du membre, la bannière (vidéo comprise). Nom réel (catalogues de
   l'admin compris), type, fin de vente, badge « Nouveau » pour un article de moins de 14 jours, « Possédé » pour un
   article acheté.
5. « Essayer » ouvre l'aperçu du profil du membre (sa photo, son cadre, sa bannière) avec l'article à la place du
   sien, sans rien enregistrer.
6. Achat : un article trop cher indique ce qu'il manque ; sinon une fenêtre de confirmation dit le prix et le solde
   restant. Après l'achat, un lien « Le porter » mène à l'éditeur du profil.
7. Un visiteur non connecté voit les articles en vitrine avec « Connecte-toi pour acheter ».

### Admin `/admin/boutique`

8. Chiffres en tête : articles en vente, ventes, pelles encaissées. Formulaire de mise en vente avec l'aperçu du
   cosmétique choisi, sous son nom réel.
9. Articles en cartes avec aperçu, état (en vente, à venir, terminé, en pause), prix, fenêtre de vente et nombre de
   ventes ; filtre par état.
10. Actions : modifier le prix et les dates ; mettre en pause et reprendre ; supprimer. La suppression efface
    l'article de la base : les acheteurs gardent leur cosmétique (la possession tient au cosmétique, pas à
    l'article) et leur historique de pelles (libellé et montant inchangés). Confirmation obligatoire.

### Qualité

11. Tests fonctionnels (public anonyme, modification, pause et reprise, suppression avec acheteur, ventes) et tests
    front. Gates verts.

## Tasks / Subtasks

- [ ] **Task 1** (AC 7, 10, 11) - API : boutique publique, modifier, pause, reprise, suppression réelle, ventes.
- [ ] **Task 2** (AC 1-7) - Page `/boutique` en onglets, cartes avec aperçu, essayage, achat, lien d'en-tête.
- [ ] **Task 3** (AC 8-10) - Page admin.
- [ ] **Task 4** (AC 11) - Tests et gates.

## Notes techniques

- La vente se lit dans le registre : clé unique `shop:{userId}:{itemId}` des mouvements `ShopPurchase` (41.7).
- `DELETE /api/v1/admin/shop/items/{id}` supprime vraiment ; la pause passe par `POST .../pause` et `.../resume`.
- Les noms des cosmétiques viennent des catalogues du code et des catalogues publics de l'API (41.10, 41.11).
