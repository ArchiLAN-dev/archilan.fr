# Story 56.5 - Durée cible, avertissement au YAML et préréglages par jeu

**Status:** ready-for-dev
**Epic:** 56 - Équilibrage des parties (`_bmad-output/planning-artifacts/epics/epic-56-equilibrage.md`)
**Date:** 2026-11-21
**Issue:** #1178
**Dépend de:** 56.1 (mesure d'un test de slot), 56.2 (durée estimée).

## Story

En tant que joueur qui règle son YAML pour un event,
je veux savoir combien de temps mon monde va durer par rapport à la cible de l'event, et choisir un format tout prêt,
afin de venir avec un monde à la bonne taille.

## Décisions

1. **Durée cible** : champ sur l'event (heures). Par défaut, la durée de l'event moins 20 %. Aussi sur un template
   hebdo et sur une partie privée (optionnel).
2. **Avertissement** : à l'enregistrement du YAML d'un slot d'event, le joueur peut lancer « Tester ma config » (le
   test de 11.11 ouvert au joueur pour son slot, avec les mêmes garde-fous). Le résultat affiche « environ N checks,
   environ X h ; la cible de l'event est Y h ». Au-delà de la cible, un message d'alerte, sans blocage.
3. **Préréglages par jeu** : sur la curation des options d'un jeu (9.51 à 9.53), un admin définit jusqu'à trois
   préréglages nommés (court, moyen, long), chacun un ensemble de valeurs d'options. Le joueur en applique un à son
   YAML en un clic ; il peut ensuite modifier les valeurs.
4. Le test joueur est limité (un test en cours par slot, et un nombre de tests par heure) pour ne pas saturer
   l'orchestrateur.

## Critères d'acceptation

1. Un event a une durée cible, par défaut la durée de l'event moins 20 %, modifiable par un admin.
2. Un joueur teste son slot et voit sa taille, sa durée estimée et la cible ; au-delà, une alerte.
3. Un admin définit des préréglages pour un jeu ; un joueur en applique un à son YAML.
4. La limite de tests est respectée.

## Tests et gates

5. Fonctionnels : cible par défaut, test joueur (droits, limite), préréglages. Front : résultat du test, alerte,
   application d'un préréglage.
6. `composer gates` et `pnpm gates` verts.

## Tasks / Subtasks

- [ ] **Task 1** - Durée cible sur l'event, le template hebdo et la partie privée.
- [ ] **Task 2** - Test de slot ouvert au joueur, limite, affichage du résultat.
- [ ] **Task 3** - Préréglages par jeu : stockage, écran admin, application côté joueur.
- [ ] **Task 4** - Tests et gates.

## Dev Notes

- Mémoire `yaml_roundtrip_lossy` : le YAML envoyé au générateur n'est pas celui du joueur ; un préréglage s'applique
  aux valeurs d'options, pas au texte.
