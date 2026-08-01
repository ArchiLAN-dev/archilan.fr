# Story 32.17 - Échanges : filtre progression et balance

## Status

Draft

## Story

**En tant que** joueur consultant le récap,
**je veux** distinguer les objets qui ont fait avancer la partie du simple remplissage,
**afin de** voir qui a réellement débloqué qui.

## Contexte

Le diagramme des échanges (story 9.49) compte **tous** les objets. Sur la partie de référence, 117
des 191 objets sont du remplissage sans conséquence, 60 sont de la progression et 14 sont « utiles ».
Un ruban épais dit donc surtout que le joueur a beaucoup checké, pas qu'il a été utile aux autres.
La question intéressante - qui a débloqué qui - est noyée dans le bruit.

Second manque : le diagramme montre des volumes, jamais de la réciprocité. Sur la partie de
référence, LM a donné 82 et reçu 70, P a donné 109 et reçu 121. Ce solde raconte qui a porté la
partie, et il n'est visible nulle part.

## Acceptance Criteria

**AC1 - Un basculement « Tous les objets / Progression uniquement »** au-dessus du diagramme
recompose les rubans et les compteurs.

**AC2 - Une bande de soldes** sous le diagramme : par slot, donné, reçu, et le solde net signé.

**AC3 - Dégradation honnête.** Le basculement n'est pas affiché si la partie ne porte aucun flag
(parties antérieures à la story 32.9) - proposer un filtre qui renverrait un diagramme vide serait
pire que de ne pas le proposer.

**AC4 - Le miroir accessible suit le filtre actif.** Le tableau `sr-only` décrit ce qui est affiché.

**AC5 - Une seule source de vérité.** Les deux vues sortent du même calcul serveur (voir Dev Notes),
pas d'un recalcul client qui pourrait diverger du diagramme « Tous ».

**AC6 - Quality gates.** `composer gates` et `pnpm gates` verts.

## Tasks / Subtasks

- [ ] Task 1: `FeedGraphBuilder` compte aussi les objets de progression par arête et par entrée
      locale ; `SessionRecap` porte `progressionCount` à côté de `count` (AC5).
- [ ] Task 2: migration + exposition dans `SessionRecapQuery` et le type TS `RecapEdge` /
      `RecapLocalItem` (AC5).
- [ ] Task 3: reconstruction des projections existantes via `app:sessions:rebuild-recap` (AC5).
- [ ] Task 4: basculement et bande de soldes dans `ExchangeSankey` (AC1, AC2, AC3, AC4).
- [ ] Task 5: tests - builder (comptage double), rendu (filtre sans flags masqué) (AC3, AC5).
- [ ] Task 6: gates (AC6).

## Dev Notes

**Pourquoi côté serveur plutôt que côté client.** Il serait tentant de recalculer le graphe filtré
dans le navigateur depuis `feed`, qui porte déjà `item.flags`. Deux raisons de ne pas le faire :

1. **Le flux affiché n'est pas garanti complet.** Avant de dériver quoi que ce soit de `feed` côté
   client, il faut vérifier ce que renvoie réellement l'endpoint de flux (pagination, plafond). Si
   le flux est tronqué, le diagramme filtré contredirait le diagramme non filtré, sans que rien ne
   le signale.
2. La projection est déjà l'endroit où ce comptage se fait (story 9.48). Ajouter un compteur à côté
   de l'existant coûte une ligne dans le builder et garantit que les deux vues sont cohérentes par
   construction.

**Flags AP.** Bit 1 = progression, bit 2 = utile, bit 4 = piège. `isProgressionFind` (feed-api) teste
déjà le bit 1 côté front ; côté API, le champ est `item_flags` sur `session_feed_event`. Un flag
`null` (bridge ancien) n'est **pas** de la progression, mais il ne faut pas non plus le compter comme
du remplissage certain - d'où AC3, qui masque le filtre plutôt que de trancher.

**Reconstruction.** Les projections existantes n'ont pas le nouveau compteur et ne se répareront pas
seules. La commande `app:sessions:rebuild-recap <sessionId>...` créée avec le correctif 9.48 est le
véhicule prévu ; prévoir de la passer sur les parties terminées au déploiement.

**Solde.** Donné et reçu incluent les objets gardés ou non selon la convention retenue en story
32.16 - trancher une fois pour les deux stories, pas deux fois.
