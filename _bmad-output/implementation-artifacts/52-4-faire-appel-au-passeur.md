# Story 52.4 - Faire appel au Passeur (pelles d'argent ou de bronze)

**Status:** ready-for-dev (relue après le spike 52.1)
**Epic:** 52 - Le Passeur (`_bmad-output/planning-artifacts/epics/epic-52-passeur.md`)
**Date:** 2026-11-09
**Branche:** `feature/epic-52-story-4-faire-appel-au-passeur` (depuis `develop`)
**Dépend de:** 52.1 (opération du bridge), 52.2 (créneaux), 52.3 (candidats).

## Story

En tant que joueur bloqué par un objet rangé chez un absent,
je veux payer des pelles pour faire ouvrir ce coffre,
afin de reprendre ma partie sans attendre son arrivée.

## Contexte (cartographie du 2026-11-09)

- Achat d'indice en pelles : `api/src/Sessions/Application/Command/BuyHintWithPelles.php` (débit, commande admin
  via le bridge, remboursement si échec), `PelleHintTerms`, `PelleHintPurchase`. Le Passeur suit le même circuit.
- Registre : `RecordPelleMovement` est le seul point d'écriture (`api/src/Wallet/`), `PelleKind` (`gold`, `event`,
  `bronze`), `PelleReason`.
- Pelles de bronze d'une partie privée : `pelle_movement.run_id`, réglages `bronze*` dans `SessionServerConfig`.
- Réglages de partie : `SessionServerConfig` (`pelleHints`, prix) ; réglages d'événement : contexte `Events`.

## Décisions (validées par Jean le 2026-11-09)

1. **Monnaie** : en événement, pelles d'argent de l'événement **seulement** (pas d'or en secours) ; en partie
   privée, pelles de bronze de la partie. Jamais en hebdo.
2. **Prix** : prix de base par défaut = 3 fois le prix d'un indice d'objet ; baisse de 10 % du prix de base toutes
   les 15 minutes de blocage (depuis le début du BK suivi par `SlotBlockTracker`), plancher 40 %. Arrondi à
   l'entier supérieur. L'attente compte depuis le début du BK **en cours** : une sortie de BK (le joueur a reçu de
   quoi avancer) remet le prix au plein tarif. Un épisode clos par un redémarrage du serveur (40.3) puis rouvert
   sans aucun objet reçu entre les deux compte comme le même BK, pour qu'une relance ne pénalise pas le joueur
   (proposé, à confirmer par Jean). Le prix affiché est celui débité (recalculé au moment de l'achat ; écart refusé avec 409
   `passeur_price_changed` pour que le joueur confirme le nouveau prix).
3. **Part de l'absent** : 50 % du prix payé (arrondi à l'inférieur) versés à l'absent dans la même monnaie ; le
   reste est détruit. Jamais de création de pelles.
4. **Limite** : 5 ouvertures par monde absent et par jour (jour civil Europe/Paris).
5. **Désactivé par défaut** ; activé par l'orga sur l'événement, par le propriétaire sur une partie privée.
6. **L'absent ne peut pas refuser.** Il est notifié à chaque ouverture.
7. **Nouvelles raisons du registre** : `passeur_purchase`, `passeur_refund`, `passeur_share`. Clés d'idempotence
   par achat (`passeur:{purchaseId}:...`).
8. **Trace** : une table `passeur_opening` (session, acheteur, slot acheteur, slot absent, emplacement, objet,
   prix payé, part versée, statut, dates) sert à la limite par jour, à 52.5 et au journal admin.

## Apports du spike 52.1

- Route du bridge : `POST /slots/{slot}/locations/{location_id}/send` (jeton interne). 200 = ouvert (emplacement,
  objet, destinataire) ; refus `{"code", "message"}` : 409 `already_checked`, 404 `unknown_location` ou
  `unknown_slot`, 422 `refused`, 504 `timeout`, 503 `ws_disconnected`. Un 504 est ambigu (l'ouverture a pu se faire
  sans que le bridge la voie) : avant de rembourser, relire l'état de l'emplacement (déjà ouvert = succès).
- Chaque ouverture laisse un écho `!admin /send_location ...` dans le chat : le masquer dans le feed du site et
  publier le message du Passeur à la place (critère 2).

## Critères d'acceptation

1. Sur sa page de partie, un joueur bloqué avec un candidat (52.3) voit : le joueur absent, l'objet attendu, le prix
   du moment, son solde dans la bonne monnaie, un bouton « Faire appel au Passeur ».
2. Achat réussi : débit, ouverture par le bridge, part créditée à l'absent, ligne `passeur_opening` en succès,
   message dans le feed (« Le Passeur a ouvert un coffre du monde de Bob pour Alice »), notification à l'absent.
3. Échec du bridge ou refus du serveur : remboursement intégral, aucune part versée, ligne en échec, message clair.
4. Refus avant débit : Passeur désactivé, pas de candidat, solde insuffisant, limite du jour atteinte, prix changé,
   session pas en cours.
5. En événement, l'or n'est jamais débité, même si le solde d'argent est insuffisant.
6. Réglages (événement et partie privée) : activation, prix de base, baisse, plancher, part de l'absent, limite par
   jour ; délai d'absence en partie privée (2 h par défaut). Valeurs bornées.
7. Deux achats simultanés sur le même emplacement : un seul aboutit (verrou), l'autre est remboursé ou refusé
   avant débit.

## Tests et gates

8. Unitaires : calcul du prix (paliers, plancher, arrondi), part, limite par jour.
9. Fonctionnels : achat réussi (argent et bronze), échec avec remboursement, chaque refus, or jamais débité,
   concurrence, idempotence d'une requête rejouée.
10. Front : proposition, confirmation avec prix, états d'erreur ; réglages admin et propriétaire.
11. `composer gates` et `pnpm gates` verts.

## Tasks / Subtasks

- [ ] **Task 1** (AC 6) - Réglages événement et partie privée, migration.
- [ ] **Task 2** (AC 2-5, 7) - Commande d'achat, raisons du registre, table `passeur_opening`, port vers le bridge.
- [ ] **Task 3** (AC 2) - Feed et notification à l'absent (type de notification réglable, push).
- [ ] **Task 4** (AC 1) - Proposition sur la page de partie (événement et partie privée).
- [ ] **Task 5** (AC 11) - Gates verts, Dev Agent Record.
