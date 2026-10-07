# Story 41.12: Relancer un co-joueur inactif

**Status:** draft
**Epic:** 41 - Des amis qui servent à jouer
**Date:** 2026-10-02

## Story

En tant que joueur d'une run perso en asynchrone,
je veux envoyer une relance à un co-joueur qui n'a pas joué depuis plusieurs jours,
afin que la partie avance sans devoir le harceler sur Discord.

## Contexte

Équivalent du « nudge » Duolingo. Dans un multiworld asynchrone, un joueur absent bloque souvent les autres
(items à envoyer). Une run sans activité passe `idle` après 1 h (epic 17) et ne repart que sur relance manuelle :
c'est justement le cas où la relance sert. La dernière activité d'un slot se lit dans le feed persisté
(`SessionFeedEvent.occurred_at` où `sender_slot` = le slot, c'est-à-dire son dernier check).

## Critères d'acceptation

1. Sur la page d'une run perso `active` ou `idle`, à côté d'un participant dont aucun slot n'a fait de check
   depuis **au moins 48 h** (ou jamais, si la run a démarré il y a plus de 48 h), un bouton « Relancer » est
   visible des autres participants.
2. La relance envoie une notification `run_nudge` (cloche + push) : « *X* attend ta prochaine session dans
   *Titre* », lien vers la run.
3. Plafond : une relance par (run, destinataire) toutes les 24 h, tous expéditeurs confondus ; sinon l'expéditeur
   voit « Déjà relancé il y a *N* h ».
4. Le destinataire peut couper les relances pour une run donnée (« Ne plus me relancer pour cette partie »).
5. Pas de relance sur une run `draft`, `completed` ou `cancelled`, ni vers un participant dont tous les slots sont
   released ou ont atteint l'objectif, ni vers soi-même.
6. `composer gates` et `pnpm gates` passent.

## Tasks / Subtasks

- [ ] **Application** : lecture de la dernière activité par participant (DBAL, une requête par run) ; commande
      `NudgeRunParticipant`.
- [ ] **Domaine/Migration** : journal des relances (plafond) et opt-out par (run, utilisateur).
- [ ] **Notifications** : type `run_nudge` poussable.
- [ ] **Front** : bouton et états sur la page de run.
- [ ] Tests (seuil 48 h, jamais joué, plafond partagé, opt-out, statuts exclus) et gates.

## Notes

- Pas besoin d'être amis : tout co-participant peut relancer.
- Idée écartée pour l'instant : chiffrer « tu as *N* items de progression pour les autres » (lecture du spoiler ou
  de la multidata, coûteuse et sensible aux spoilers).
