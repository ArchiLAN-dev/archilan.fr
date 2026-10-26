# Story 41.58: Plusieurs parties de blind test pendant un événement

**Status:** ready-for-dev
**Epic:** 41 - Pelles
**Date:** 2026-10-26
**Dépôt :** `archilan-blind-test` (app seule, aucun changement sur archilan.fr)
**Origine :** Jean le 2026-10-26 : pendant un même événement, on veut pouvoir lancer plusieurs blind tests. Aujourd'hui
une partie est l'événement lui-même (`game.event_id` est la clé), et tout ce que font les joueurs (trouvailles,
essais, erreurs, journal d'antenne, crédits) est rangé par événement : une deuxième playlist hérite des résultats de la
première, et il n'existe aucun moyen de repartir de zéro.

Décisions de Jean (2026-10-26) :
- le plafond de pelles par membre reste celui de l'**événement**, partagé par toutes ses parties (aucun changement
  sur le site) ;
- le score et le rang du téléphone sont ceux de la **partie** active.

## Story

En tant qu'animateur d'un événement,
je veux lancer plusieurs parties de blind test au cours du même événement, chacune avec sa playlist et son
classement,
afin d'enchaîner des manches (thèmes, horaires) sans que les résultats de l'une se mélangent à ceux de l'autre.

## Critères d'acceptation

1. **Modèle** : une partie (`Game`) a son propre identifiant, l'événement auquel elle appartient, un numéro dans
   l'événement (1, 2, 3…), un nom (par défaut « Partie N ») et sa playlist. Un événement a zéro, une ou plusieurs
   parties. Une seule partie est active dans toute l'app, comme aujourd'hui : c'est elle que la régie joue et que les
   téléphones voient.
2. **Résultats par partie** : trouvailles (`found_source`), essais et erreurs (`player_track`), journal d'antenne
   (`on_air_entry`) et crédits en attente (`pending_credit`) sont rangés par partie. Les contraintes d'unicité passent
   de l'événement à la partie. Une nouvelle partie démarre à zéro pour tous les joueurs.
3. **Migration sans perte** : la partie existante de chaque événement devient sa « Partie 1 », et toutes les lignes
   existantes lui sont rattachées. L'état de jeu (active, démarrée, morceau à l'antenne) est conservé.
4. **Console, liste des parties** : pour l'événement choisi, la console liste ses parties (nom, nombre de morceaux,
   nombre de joueurs, état : active, en cours, terminée, pas commencée).
   - « Nouvelle partie » met le brouillon actuel dans une nouvelle partie de l'événement, qui devient active.
   - « Activer » rend active une autre partie (de cet événement ou d'un autre) ; la régie et les téléphones suivent.
   - « Renommer » change le nom.
   - Une partie non active se consulte en lecture : son classement final et ses morceaux.
5. **Remplacer la playlist de la partie active** : le bouton actuel (« Mettre / Remettre la playlist dans la partie »)
   agit sur la partie active de l'événement, ou crée la Partie 1 s'il n'y en a pas. Avec « Recommencer la partie du
   début », il efface aussi les résultats de cette partie (trouvailles, essais, erreurs, journal, crédits en attente).
   Les pelles déjà versées ne sont pas reprises, et un morceau retrouvé après le redémarrage n'en rapporte pas de
   nouvelles (même clé de crédit, voir 7).
6. **Téléphone** : le score, le rang, le morceau en cours et les récaps « Morceaux révélés » sont ceux de la partie
   active. Le bandeau affiche le nom de la partie quand l'événement en a plusieurs.
7. **Pelles** : la clé de crédit inclut la partie, pour qu'un morceau rejoué dans une autre partie rapporte de nouveau.
   La Partie 1 garde la clé actuelle (`blindtest:{event}:{track}:{source}:{user}`), donc les crédits déjà envoyés
   restent dédoublonnés. Les parties suivantes ajoutent `:{numéro}`. La clé reste sous 120 caractères (limite du site).
   Le plafond reste celui de l'événement, calculé par le site : rien ne change côté site.
8. **Régie et commande** : la régie joue la partie active sans changement visible. `app:game:load` (commande
   console) accepte une option pour créer une nouvelle partie plutôt que de remplacer l'active.
9. **Tests** : migration (une base à une partie par événement se retrouve en Partie 1 avec toutes ses lignes),
   deux parties d'un même événement ont des scores séparés, la clé de crédit des parties 1 et 2, « Recommencer »
   efface les résultats de la partie seulement, activation d'une partie. `composer gates` vert.

## Tâches

- [ ] Entité `Game` : identifiant propre (entier auto-incrémenté), `eventId` non unique, `number`, `name`,
      `createdAt` ; reconstruire la table SQLite dans la migration.
- [ ] `game_id` sur `found_source`, `player_track`, `on_air_entry`, `pending_credit` ; remplissage depuis
      `event_id` ; index et contraintes d'unicité par partie.
- [ ] `GameService` et `GameMonitor` : lectures et écritures par partie ; `load` remplace ou crée ; `create`,
      `activate`, `rename` ; effacement des résultats au redémarrage.
- [ ] `Credit::key()` avec le numéro de partie (format historique pour la Partie 1) ; `PendingCredit` le garde.
- [ ] `AdminController` : routes de liste, création, activation, renommage, consultation d'une partie.
- [ ] Console (`admin/console.html.twig`) : bloc « Parties » de l'événement.
- [ ] Page joueur : nom de la partie dans le bandeau.
- [ ] `LoadGameCommand` : option de nouvelle partie.
- [ ] Tests fonctionnels et unitaires ; `composer gates`.

## Notes

- Le brouillon (`playlist_draft`) reste par événement : c'est l'atelier où l'on tire et vérifie une playlist avant de
  la mettre dans une partie. Pour préparer la manche suivante, on retire un brouillon puis « Nouvelle partie ».
- Ne pas commiter `src/Repository/` (fichiers locaux non suivis).
