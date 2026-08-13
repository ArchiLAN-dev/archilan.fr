# Story 37.7: Fermer l'exposition publique du bridge

**Status:** ready-for-dev
**Epic:** 37 - Accès WSS aux serveurs Archipelago
**Date:** 2026-08-13
**Dépend de :** la bascule de 37.1-37.3 en production. Elle prouve sur cette machine que le routage
par nom de conteneur fonctionne - l'hypothèse centrale de cette story.

> **Arbitrage du 2026-08-13 :** le défaut de l'archivage (décrit plus bas) est corrigé **dans cette
> story**, pas en hotfix séparé. Il est réel aujourd'hui et le restera jusqu'à son exécution ; c'est
> assumé, parce que le corriger isolément signifierait toucher une seizième fois à une construction
> d'adresse que cette story supprime. **Conséquence à ne pas perdre de vue : chaque run archivée
> d'ici là perd l'état de ses slots**, définitivement - l'archive est écrite une fois.

## Story

En tant que responsable de la plateforme,
je veux que l'API joigne le bridge d'une session par le réseau interne plutôt que par l'adresse
publique du serveur,
afin que la dernière socket publique d'une run puisse être refermée, et que le chemin
API vers bridge cesse de dépendre de ce qui est joignable depuis Internet.

## Context

L'epic 37 referme le port Archipelago de chaque run. Il laisse ouvert celui du bridge, et l'a écrit
noir sur blanc dès le départ : « c'est une seconde exposition, réelle, mais qui demande de revoir le
chemin API vers bridge ». Cette story fait cette revue.

### L'état constaté le 2026-08-13

`BRIDGE_HTTP_HOST=archilan.fr` en production. L'API appelle donc le bridge d'une session sur
`http://archilan.fr:{bridgePort}` : elle **sort du conteneur vers l'adresse publique et revient**
par le port publié sur l'hôte. Conséquences :

- les ports `25000-25099` sont joignables depuis Internet, avec pour seule barrière le token du
  bridge ;
- **filtrer cette plage au pare-feu couperait l'API de tous les bridges**, donc la génération et le
  suivi des runs. Le durcissement le plus évident est aujourd'hui un piège ;
- le chemin dépend du DNS public et du retour en épingle par la passerelle, pour joindre un
  conteneur qui tourne sur la même machine.

### Le chemin interne existe déjà

Le conteneur s'appelle `archilan-bridge-{sessionId}` (`orchestrateur/internal/docker/client.go`), il
écoute sur `5000` à l'intérieur, et il vit sur `BRIDGE_NETWORK` - le réseau `default` du projet, que
`api-web` et `api-worker` partagent. `http://archilan-bridge-{sessionId}:5000` est donc joignable
**dès aujourd'hui**, sans rien déployer. C'est exactement le mécanisme que 37.2 et 37.3 utilisent
pour le serveur Archipelago.

### Ce que l'analyse a révélé en chemin

**Un défaut de production, silencieux depuis toujours.**
`ArchiveRunJobHandler::fetchBridgeState()` construit `http://localhost:{bridgePort}/state`. Ce code
tourne dans le conteneur `api-worker`, où `localhost` désigne **le conteneur lui-même**, jamais
l'hôte. L'appel ne peut donc pas aboutir ; il échoue dans un `catch (\Throwable)` qui journalise un
`warning` et renvoie une liste vide. **Les runs archivées perdent l'état de leurs slots**, sans que
rien ne le signale ailleurs que dans les logs.

Ce n'est pas une conséquence de cette story, c'est une conséquence de la dispersion qu'elle corrige :
**quinze endroits construisent une URL de bridge**, dans trois fichiers, et un seizième a divergé
sans que personne le voie.

## Acceptance Criteria

### Une seule façon de joindre un bridge

1. Un composant unique construit l'adresse du bridge d'une session. Il est **pur**, testable sans
   HTTP, et c'est le **seul** endroit du code qui sait à quoi ressemble cette adresse.
2. Les **quinze** sites de construction actuels passent par lui : `SendBridgeCommand`,
   `PlayerStateController` (la majorité), `WeeklyRunSlotStateController`, et
   `ArchiveRunJobHandler` - qui **cesse par la même occasion de viser `localhost`**.
3. Une recherche de `http://%s:%d` et de `localhost:` dans `api/src` ne renvoie plus aucune
   construction d'adresse de bridge. C'est le critère qui empêche la dispersion de revenir.

### Le chemin interne

4. L'adresse produite vise le conteneur par son **nom** et son port interne :
   `http://archilan-bridge-{sessionId}:5000`. Le port hôte de la session n'est plus utilisé pour
   joindre le bridge.
5. Le nom du conteneur est un **contrat avec l'orchestrateur** (`archilan-bridge-{sessionId}`),
   écrit comme tel dans le code des deux côtés. Aucun test ne peut le tenir : ils vivent dans deux
   dépôts.
6. `BRIDGE_HTTP_HOST` disparaît, ou ne subsiste que documenté comme un vestige inutilisé. Une
   variable qui ne sert plus mais reste lue est une invitation à des heures perdues.

### Vérification avant de refermer quoi que ce soit

7. Le parcours complet passe avec le chemin interne, **avant** toute fermeture de port : lancement,
   consultation de l'état d'un slot, envoi d'une commande, run hebdomadaire, et **archivage d'une
   run terminée** - dont on vérifie qu'il ramène enfin des slots non vides.
8. Une trace prouve que l'archivage était cassé avant et ne l'est plus après. Corriger un défaut
   invisible sans le rendre visible, c'est se préparer à le réintroduire.

### Fermeture

9. Une fois le chemin interne validé en production, l'orchestrateur **cesse de publier le port du
   bridge sur l'hôte** (`orchestrateur/internal/docker/client.go`, `Create`), sur le modèle exact de
   ce que 37.3 a fait pour le serveur Archipelago : réglage explicite, refus au démarrage des
   combinaisons injoignables, et développement local conservé.
10. Après fermeture, `ss -lntp | grep ':25[0-9][0-9][0-9]'` ne renvoie plus rien, et la plage peut
    être filtrée au pare-feu sans casser l'API.
11. `AP_SERVER_HOST_PORT`, transmis au conteneur bridge et lu nulle part, est retiré à cette
    occasion - c'est le nettoyage laissé ouvert par 37.3.

### Qualité

12. `composer gates` vert. Les tests fonctionnels qui simulent un bridge sont adaptés à la nouvelle
    forme d'adresse.

## Tasks / Subtasks

- [ ] **Task 1** (AC 1-3). Écrire le composant d'adressage et ses tests ; router les quinze sites
  vers lui, `ArchiveRunJobHandler` compris.
- [ ] **Task 2** (AC 4-6). Basculer sur le nom de conteneur, retirer `BRIDGE_HTTP_HOST`.
- [ ] **Task 3** (AC 12). `composer gates`.
- [ ] **Task 4** (AC 7-8). Parcours complet en production, dont l'archivage. **Avant** toute
  fermeture.
- [ ] **Task 5** (AC 9-11). Dépôt orchestrateur : fin de la publication du port du bridge, retrait
  d'`AP_SERVER_HOST_PORT`.
- [ ] **Task 6** (AC 10). Fermeture vérifiée, puis filtrage de la plage au pare-feu.

## Dev Notes

- **L'ordre consommateur-puis-fermeture n'est pas négociable**, et il est l'inverse de celui que
  l'epic avait initialement écrit pour 37.1-37.3. La leçon est acquise à la dure : d'abord l'API
  cesse d'avoir besoin du port hôte, ensuite l'orchestrateur cesse de le publier. L'inverse coupe
  l'API de tous les bridges en cours.
- **Les runs déjà lancées ne posent pas de problème** : le conteneur est nommé et sur le bon réseau
  depuis toujours. Le chemin interne fonctionne aussi pour elles, sans relance.
- **Attention au timeout de 3 secondes** dans `fetchBridgeState`. Il était probablement dimensionné
  pour un appel qui échouait immédiatement ; sur un chemin qui aboutit vraiment, un bridge occupé
  peut demander davantage. À réévaluer une fois que l'appel fonctionne, pas avant.
- **Ne pas en profiter pour réécrire `PlayerStateController`.** Il concentre l'essentiel des sites
  d'appel et mérite sans doute mieux, mais mélanger un changement d'adressage et une refonte de
  contrôleur rend le diff illisible et la régression indétectable.
- **Pourquoi maintenant plutôt qu'après.** Cette story a été écrite avant la bascule de 37.1-37.3 à
  la demande de Jean, pour que la cible soit posée pendant que l'analyse est fraîche. Son exécution,
  elle, attend la bascule : c'est elle qui prouvera empiriquement, sur cette machine, que le routage
  par nom de conteneur tient.

### Project Structure Notes

- Nouveau composant : `api/src/Shared/Application/Support/` (voisin de `ArchipelagoConnectionUri`,
  même forme, même rôle).
- `api/src/Sessions/Application/Command/SendBridgeCommand.php`,
  `api/src/Sessions/Presentation/Controller/PlayerStateController.php`,
  `api/src/WeeklyRuns/Presentation/Controller/WeeklyRunSlotStateController.php`,
  `api/src/Sessions/Application/Handler/ArchiveRunJobHandler.php`.
- `api/config/services.yaml` : liaison `$bridgeHttpHost` à retirer.
- Dépôt **orchestrateur** : `internal/docker/client.go` (`Create`), `internal/config/config.go`.

### References

- [Source: _bmad-output/planning-artifacts/epics/epic-37-acces-wss-aux-serveurs-archipelago.md] - la seconde exposition, explicitement hors périmètre à l'origine
- [Source: _bmad-output/implementation-artifacts/37-3-fin-de-l-exposition-publique-du-port-ap.md] - le modèle à reproduire côté orchestrateur
- [Source: docs/deploiement-production.md] - topologie réelle, réseaux, écarts connus
- [Source: orchestrateur/internal/docker/client.go:158-178] - port publié et nom du conteneur bridge

## Dev Agent Record

### Agent Model Used

### Completion Notes List

### File List

### Change Log

| Date | Change |
|------|--------|
| 2026-08-13 | Créée. Constat déclencheur : `BRIDGE_HTTP_HOST=archilan.fr`, donc l'API sort par l'adresse publique pour joindre un conteneur voisin. Défaut découvert en chemin : `ArchiveRunJobHandler` vise `localhost` depuis un conteneur, donc l'archivage perd l'état des slots en silence depuis toujours. |
