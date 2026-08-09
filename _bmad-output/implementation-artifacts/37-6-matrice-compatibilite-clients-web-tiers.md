# Story 37.6: Matrice de compatibilité des clients web Archipelago tiers

**Status:** ready-for-dev
**Epic:** 37 - Accès WSS aux serveurs Archipelago
**Date:** 2026-08-08
**Dépend de :** rien. C'est la story à faire **en premier**, avant toute écriture de code.

## Story

En tant qu'opérateur de la plateforme,
je veux savoir quels clients web Archipelago tiers se connectent réellement à nos runs en `wss`, et
avec quelle chaîne d'adresse exacte,
afin que les stories d'affichage sachent quoi afficher, et qu'on découvre avant d'investir si l'epic
tient debout.

## Context

L'epic 37 existe parce que les clients web tiers ne peuvent pas joindre nos runs. Or **on ne contrôle
aucun de ces clients**. « Ça marche » n'est donc pas une propriété de notre code : c'est un fait
observé, sur des clients nommés, à une date donnée.

### Pourquoi cette story passe avant le code

Elle décide deux choses qu'aucune autre story ne peut décider :

1. **Ce que 37.4 et 37.5 doivent afficher.** Les clients se répartissent en deux familles
   incompatibles : ceux qui attendent une adresse nue et préfixent `wss://` eux-mêmes (leur donner
   l'URI complète produit `wss://wss://...`), et ceux qui attendent une URI complète (leur donner
   l'adresse nue les fait retomber en `ws://` ou ajouter `:38281`). Il n'existe pas une chaîne unique
   qui marche partout - c'est ce résultat qui dira si l'UI affiche une forme, deux, ou une par client.
2. **Si l'epic tient.** Si aucun client exploitable ne supporte le `wss` sur un port arbitraire,
   l'objectif de l'epic n'est pas atteignable et il faut le savoir maintenant.

### Ce que la story ne remet pas en cause

Le client **desktop** Archipelago gère `wss://` - testé par Jean le 2026-08-08. Ce point est acquis et
n'a pas à être retesté. Même si cette story conclut à un no-go côté clients tiers, la chaîne d'infra
(37.1 à 37.3) garde sa valeur : elle livre le wss pour le desktop et referme l'exposition en clair du
port Archipelago.

## Acceptance Criteria

### Banc de test

1. **Le banc reproduit l'architecture cible** : un **port de la plage du pool**
   (`PORT_RANGE_START`-`PORT_RANGE_END`, soit `25000-25099` en prod), en TLS, servi avec le
   **certificat `archilan.fr` réel** émis par le resolver letsencrypt existant.
2. **Interdit : tester sur un sous-domaine sans port** (ex. `wss://ap-test.archilan.fr` sur 443). Le
   comportement de parsing du **port** est précisément la variable qu'on mesure ; un test sans port
   mesure autre chose et ne conclut rien.
3. **Interdit : certificat auto-signé.** Un navigateur rejette un WebSocket sur certificat invalide
   *silencieusement*, sans interstitiel. Un banc auto-signé produirait des échecs indiscernables d'une
   incompatibilité client, et invaliderait toute la matrice.
4. Le run de test est joignable, avec un mot de passe connu et au moins un slot utilisable. Une run
   personnelle lancée pour l'occasion suffit ; son hôte et son port conteneur sont la cible du routeur.
5. **Le banc est démonté à la fin** : entrypoint temporaire et routeur de fichier retirés, port refermé.

### Liste des candidats

6. Les **critères d'inclusion** d'un client sont écrits : client web accessible publiquement, capable
   de viser un serveur **arbitraire** (hôte + port saisis par l'utilisateur), et encore maintenu.
7. Le **client texte web d'Archipelago (WebHost)** est le premier candidat évalué, en tant
   qu'implémentation de référence. S'il ne peut pas viser un serveur externe, c'est un **résultat à
   consigner**, pas un échec du test.
8. Au moins **trois candidats** sont évalués, ou bien la liste complète des candidats trouvés si elle
   en compte moins - avec dans ce cas la trace de la recherche menée.

### Résultats par client

9. Pour chaque client : **nom, URL, date du test, version affichée si elle l'est**, verdict de
   connexion.
10. **La chaîne d'adresse qui a fonctionné est consignée verbatim**, caractère pour caractère. C'est
    l'entrée directe de 37.4 et 37.5 ; une paraphrase (« l'URI complète ») ne suffit pas.
11. **Les formes qui ont échoué sont consignées aussi**, avec leur **mode d'échec observé** : erreur
    affichée, échec silencieux, timeout. 37.5 devra aider un joueur qui se trompe de forme, ce qui
    suppose de savoir à quoi ressemble l'erreur de son côté.
12. Au moins une connexion est **laissée ouverte et inactive 15 minutes minimum**, et le résultat est
    consigné. Cette observation alimente le risque d'idle timeout que 37.1 doit trancher.

### Verdict et artefact

13. Un **verdict go/no-go explicite** est écrit : au moins un client tiers exploitable, ou non.
14. La matrice est publiée dans **`docs/archipelago-web-clients.md`**, datée, avec une note indiquant
    que le résultat **périme** (les tiers changent sans préavis, et la matrice devra être rejouée).
15. La conclusion sur la ou les formes d'adresse à afficher est écrite noir sur blanc, à destination de
    37.4 et 37.5.

## Tasks / Subtasks

- [ ] **Task 1** (AC 1-4). Monter le banc : un entrypoint temporaire sur un port du pool dans
  `traefik/traefik.yml`, un routeur TCP TLS via le file provider vers un run vivant, certificat réel.
- [ ] **Task 2** (AC 6-8). Établir la liste des candidats selon les critères, en commençant par le
  client WebHost.
- [ ] **Task 3** (AC 9-11). Tester chaque client, sur chaque forme d'adresse plausible, et consigner
  succès **et** échecs.
- [ ] **Task 4** (AC 12). Observation de tenue sur connexion inactive longue.
- [ ] **Task 5** (AC 13-15). Rédiger `docs/archipelago-web-clients.md` : matrice, verdict, conclusion
  sur la forme d'adresse.
- [ ] **Task 6** (AC 5). Démonter le banc et vérifier que le port est refermé.

## Dev Notes

- **Formes d'adresse à essayer systématiquement sur chaque client**, parce que c'est la variable
  mesurée : `archilan.fr:25099`, `wss://archilan.fr:25099`, `archilan.fr` seul, `wss://archilan.fr`.
  Consigner le comportement des quatre, pas seulement de celle qui marche.
- **Ne pas conclure sur un seul client.** Un client qui marche ne dit rien de la forme à afficher aux
  utilisateurs des autres.
- **Ne pas « corriger » un client qui échoue.** Le but est de mesurer l'existant, pas de le faire
  marcher. Un échec proprement documenté a autant de valeur qu'un succès.
- **Le banc demande un redémarrage de Traefik** (les entrypoints sont de la configuration statique), et
  ce redémarrage a un rayon d'action bien plus large que le test : Traefik porte aussi le site, l'API et
  Mercure (`docker-compose.prod.yml`). Toutes les connexions en cours tombent, y compris les parties
  Archipelago en cours si 37.1-37.3 sont déjà livrées. **À faire sur un créneau calme, en prévenant**, ou
  sur une instance de test si l'occasion se présente. C'est exactement la contrainte que 37.1 devra
  industrialiser - noter au passage tout ce qui coince, ça l'alimente.
- **Ne pas oublier le démontage.** Un entrypoint de test laissé en place, c'est un port ouvert en
  production sans routeur pour le justifier.
- Cette story **n'écrit aucun code applicatif**. `composer gates` et `pnpm gates` ne sont pas concernées ;
  le livrable est un document et un banc démonté.

### Project Structure Notes

- Livrable : `docs/archipelago-web-clients.md` (nouveau). `docs/` porte déjà les documents de
  connaissance projet et de passation ops (`docs/seo-measurement.md`).
- Modifications **temporaires uniquement**, à ne pas commiter : `traefik/traefik.yml` (entrypoint de
  test) et un fichier du répertoire dynamique de Traefik.
- La matrice alimentera ensuite les tutoriels de l'epic 31, qui sont des enregistrements en base
  éditables par un admin - pas un fichier du dépôt. `docs/` est la source durable, les tutoriels en sont
  la restitution joueur.

### References

- [Source: _bmad-output/planning-artifacts/epics/epic-37-acces-wss-aux-serveurs-archipelago.md]
- [Source: traefik/traefik.yml] - entrypoints statiques, resolver letsencrypt DNS-01 OVH
- [Source: .env.prod.example:50-51] - `PORT_RANGE_START=25000` / `PORT_RANGE_END=25099`
- [Source: api/src/Sessions/Application/Support/TraefikConfigBuilder.php] - le générateur de routeurs
  que 37.2 adaptera au routage par port

## Dev Agent Record

### Agent Model Used

### Completion Notes List

### File List

### Change Log

| Date | Change |
|------|--------|
| 2026-08-08 | Créée. Story de mesure, à exécuter avant tout code de l'epic : elle décide la forme d'adresse à afficher en 37.4/37.5 et le go/no-go de l'epic. Banc de test contraint à reproduire l'architecture cible (port du pool + certificat réel), sans quoi il mesurerait autre chose. |
