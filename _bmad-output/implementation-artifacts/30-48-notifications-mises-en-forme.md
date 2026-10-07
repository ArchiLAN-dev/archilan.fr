# Story 30.48: Notifications mises en forme

**Status:** review
**Epic:** 30 - Communauté
**Date:** 2026-10-07

## Story

En tant que membre,
je veux des notifications lisibles d'un coup d'œil, avec un visuel et l'essentiel mis en avant,
afin de voir tout de suite ce qui m'arrive et d'agir sans quitter la cloche.

## Contexte

Une notification n'était qu'une phrase et une heure. L'API envoyait déjà l'avatar de l'auteur sans qu'il soit
affiché. Maquette validée par Jean le 2026-10-07 (canvas « Notifications enrichies ») : visuel à gauche, étiquette de
famille en couleur, texte mis en forme, actions en ligne, regroupement par période.

## Critères d'acceptation

1. **Visuel par type** : avatar de l'auteur (ami, commentaire, kudos) ; image du succès (ou un trophée) cerclée
   d'or ; aperçu du cosmétique gagné (miniature du cadre ou de la bannière, badge du titre à sa rareté, pastille de la
   couleur de pseudo) ; icône teintée pour les pelles, les quêtes, les parties, la modération et les apworlds.
2. **Étiquette de famille** au-dessus du texte : Succès, Récompense, Quêtes (or) ; Pelles (vert) ; Ami, Kudos,
   Commentaire, Partie (violet) ; Action requise (ambre : génération échouée, YAML à revoir, apworld) ; Modération
   (rouge).
3. **Texte** : les noms en gras (personne, succès, cosmétique, partie) ; une seconde ligne de détail (description du
   succès, origine du cosmétique, motif des pelles, slot et checks) ; le montant de pelles en pastille signée (vert
   crédit, rouge débit).
4. **Détails résolus à la lecture par l'API** (`details`, à côté de `data`, sans migration) : nom, description et image
   d'un succès ; aperçu d'un cosmétique (poster d'un cadre téléversé, image d'une bannière téléversée, rareté et icône
   d'un titre) ; identifiant d'une demande d'ami encore en attente. Un succès ou un cosmétique disparu laisse la
   notification lisible avec le texte enregistré.
5. **Actions en ligne** : Accepter / Refuser une demande d'ami encore en attente (la ligne dit ensuite « Demande
   acceptée » ou « Demande refusée ») ; « Porter » pour un cosmétique ; les autres lignes restent des liens.
6. **Regroupement** : sections Aujourd'hui, Hier, Cette semaine, Plus ancien ; les kudos d'un même jour regroupés en
   une ligne (« A, B et 2 autres t'ont envoyé un kudos ») avec les avatars superposés.
7. Pas de régression : liens actuels, « Tout marquer comme lu », compteur, temps réel, notifications push inchangés.
8. Gates verts ; tests.

## Tasks / Subtasks

- [x] **Task 1** (AC 4) - API : `NotificationDetails` (succès, cosmétique, demande d'ami), branché dans
  `NotificationService::recent()` ; tests.
- [x] **Task 2** (AC 1-3, 6) - Front : contenu structuré par type, visuel, étiquette, regroupements ; tests.
- [x] **Task 3** (AC 5) - Front : actions en ligne (demande d'ami, Porter) ; tests.
- [x] **Task 4** (AC 7, 8) - Gates.

## Dev Agent Record

- API : `NotificationDetails::of()` (Community, Application/Support) résout, pour la page lue, le nom, la
  description et l'image d'un succès, l'aperçu d'un cosmétique (poster d'un cadre téléversé, image d'une bannière,
  rareté et icône d'un titre) et la demande d'ami encore en attente (`findIncomingPending`).
  `NotificationService::recent()` ajoute `details` (null sinon) à chaque élément. Aucun stockage, aucune migration.
- Front : `notification-content.ts` (`contentFor` : étiquette, famille, visuel, phrase en segments, détail, montant,
  badge de titre, demande d'ami, « Porter » ; `sectionsOf` : Aujourd'hui, Hier, Cette semaine, Plus ancien, kudos
  d'un même jour regroupés ; `kudosTitle` ; `timeLabel`). `notification-row.tsx` (visuel : avatar avec son cadre,
  deux avatars superposés, image du succès cerclée d'or, miniature du cadre, pastille de couleur, icône teintée ;
  ligne entière en lien, actions au-dessus). La cloche passe à 25rem ; l'heure de référence est prise à l'ouverture.
  `messageFor` reste la phrase texte (repli, overlay, tests existants).
- Tests : `NotificationDetailsTest` (succès, succès supprimé, titre à sa rareté, demande en attente puis répondue),
  `notification-content.test.tsx`.
