# Story 32.18 - Rythme : indices et temps morts

## Status

Done

## Story

**En tant que** joueur relisant sa partie,
**je veux** voir quand j'ai demandé de l'aide et combien de temps j'ai attendu sans rien recevoir,
**afin de** retrouver le vécu de la partie et pas seulement sa comptabilité.

## Contexte

La courbe « Déroulé de la partie » (stories 32.7 à 32.14) montre le rythme des checks et marque les
objectifs atteints. Deux informations déjà persistées n'y apparaissent pas : les **indices**
(persistés depuis la story 32.12, 4 sur la partie de référence) et les **temps morts**, c'est-à-dire
les longues périodes pendant lesquelles un joueur ne reçoit rien. Or c'est souvent le moment le plus
marquant d'une partie - le passage où l'on est bloqué en attendant un objet qui ne vient pas.

## Acceptance Criteria

**AC1 - Les indices sont marqués** sur la timeline, visuellement distincts des marqueurs d'objectif
existants, avec au survol qui a demandé quoi.

**AC2 - Le plus long temps mort par joueur** est calculé et affiché : durée, et créneau horaire.

**AC3 - Les filtres existants continuent de fonctionner.** Masquer un joueur masque ses indices et
retire son temps mort ; les options mesure / courbe / regroupement sont inchangées.

**AC4 - Dégradation.** Une partie sans indice n'affiche pas de marqueur ni de section vide. Un
joueur avec moins de deux réceptions n'a pas de temps mort calculable et est omis.

**AC5 - Quality gates.** `pnpm gates` vert.

## Tasks / Subtasks

- [x] Task 1: fonction pure `buildDrySpells(feed)` - par slot, plus grand écart entre deux
      réceptions consécutives, testée sur les bords (AC2, AC4).
- [x] Task 2: marqueurs d'indices dans `ChecksChart`, sur le modèle des marqueurs d'objectif de la
      story 32.9 (AC1).
- [x] Task 3: câblage des filtres joueurs existants (AC3).
- [x] Task 4: tests - partie sans indice, joueur à une seule réception (AC4).
- [x] Task 5: gates (AC5).

## Dev Notes

**Temps mort : définition à figer.** Écart maximal entre deux événements `item-received`
**consécutifs dont le receveur est ce joueur**. Bornes à trancher explicitement : compte-t-on
l'attente avant la première réception (depuis le début de la partie) et après la dernière (jusqu'à
la fin) ? Recommandation : non pour les deux - ce sont des artefacts de bornage, pas du ressenti de
jeu. Documenter le choix dans le composant, sinon la métrique sera réinterprétée à la première
relecture.

**Attention au sens de `measure`.** `buildChecksSeries` bascule entre expéditeur et receveur selon
l'option « Checks trouvés / Objets reçus ». Le temps mort se calcule **toujours** côté receveur,
indépendamment de ce réglage : c'est une attente subie, pas une mesure d'activité. Ne pas le brancher
sur `measure`.

**Marqueurs d'indices.** Les événements `hint` portent la même forme d'origine qu'un objet (item,
location, sender, receiver) - story 32.12. Réutiliser le mécanisme de `ReferenceLine` déjà en place
pour les objectifs, avec un style distinct, plutôt que d'introduire un second mécanisme.

**Densité.** Sur une partie longue avec beaucoup d'indices, les marqueurs peuvent saturer l'axe.
Prévoir un plafond d'affichage ou un regroupement, et le signaler à l'écran si des marqueurs sont
masqués - un axe silencieusement tronqué se lit comme « il n'y en avait pas ».
