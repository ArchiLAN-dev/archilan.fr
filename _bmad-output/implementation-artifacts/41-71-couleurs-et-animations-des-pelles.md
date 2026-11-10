# Story 41.71: Une couleur et une animation pour chaque pelle

**Status:** done
**Epic:** 41 - Les Pelles, monnaie virtuelle d'ArchiLAN
**Date:** 2026-11-05

## Story

En tant que membre,
je veux reconnaître d'un coup d'oeil mes pelles d'or, d'argent et de bronze,
afin de savoir ce que je gagne et ce que je peux en faire.

## Contexte

Seul l'or avait une couleur (`text-warning`, répétée dans chaque `className`). Les pelles d'event et de bronze
s'affichaient dans la couleur du texte. Jean appelle « pelles d'argent » les pelles d'event : le site adopte ce mot,
pour former la suite or, argent, bronze. Le type reste `event` dans l'API. Frontend seulement.

## Critères d'acceptation

1. **Couleur** : `PelleAmount` prend `kind` (`gold`, `event`, `bronze`) et porte seul la couleur de chaque type. Or
   inchangé (`#e89420`), argent froid, bronze cuivré nettement plus brun que l'or. Plus aucun `text-warning` sur un
   montant de pelles. Sans `kind`, le montant garde la couleur de son contexte.
2. **Contraste** : les deux nouvelles couleurs atteignent 4,5:1 sur le fond et les surfaces du site.
3. **Animation** : `PelleBalance` anime la pelle d'un solde principal, selon son type.
   - Or : reflet qui balaie la pelle, puis une étincelle.
   - Argent : éclat bref et net sur la pointe.
   - Bronze : lueur de braise, lente, et une braise qui s'élève.
4. **Déclenchement** : une seule fois à l'arrivée, au survol et quand le solde augmente. Jamais quand il baisse, jamais
   de boucle.
5. **Portée** : soldes de `/compte/pelles`, solde de la carte de partie privée et solde de la boutique. Les historiques,
   les listes, l'en-tête du site et l'admin ont la couleur, sans mouvement. Les lignes d'historique restent en
   vert et rouge.
6. **Accessibilité** : aucun mouvement sous `prefers-reduced-motion`. L'animation n'ajoute rien aux lecteurs d'écran.
7. **Vocabulaire** : les textes du site disent « pelles d'argent » pour les pelles d'event, notifications comprises.
8. Gates verts, tests jest sur la couleur, le déclenchement et les libellés, vérification visuelle.

## Tâches

- [x] Tâche 1 : jetons de couleur, `kind` sur `PelleAmount`, reprise des usages (AC 1, 2)
- [x] Tâche 2 : `PelleBalance` et ses animations (AC 3, 4, 5, 6)
- [x] Tâche 3 : libellés (AC 7)
- [x] Tâche 4 : tests, gates, vérification visuelle (AC 8)

## Dev Agent Record

- `PelleAmount` porte la couleur (`kind`) ; `PelleBalance` ajoute l'animation. Une nouvelle `key` remonte la pelle, ce
  qui rejoue l'animation CSS sans effet ni minuteur.
- Contraste, sur `#0a1629`, `#111030` et `#1a1840` : argent `#b7c9de` au moins 9,9:1, bronze `#bf7650` au moins 4,7:1.
- Une prime hors partie privée garde la couleur de l'or.
- Le DOM de jest ne démarre pas avec le serveur msw global : la règle de déclenchement est testée sur `afterBalance`, et
  le rejeu vérifié dans le navigateur.
