# Story 16.14: Relance d'un run en veille par n'importe quel participant

**Status:** draft
**Epic:** 16 - Personal runs (parties privées créées par un membre)
**Date:** 2026-08-15
**Issue liée :** [#387](https://github.com/ArchiLAN-dev/archilan.fr/issues/387) - **partiellement**, voir « Rapport à #387 »

## Story

En tant que participant d'un run privé,
je veux pouvoir relancer le serveur quand il s'est mis en veille,
afin de continuer à jouer sans attendre que le propriétaire soit disponible.

## Context

Un run privé se met en veille tout seul : le watchdog d'inactivité arrête le conteneur et le run
passe en `STATUS_IDLE` (epic 17). La reprise est **manuelle** et volontaire - il n'y a pas de
réveil à la connexion, c'est une décision d'architecture prise en 17.6/17.7/17.8.

Or cette reprise passe par `PersonalRunLifecycle::start()`, gardée par `isOwnedBy` :

```php
if (!$run->isOwnedBy($callerId)) {
    throw new ForbiddenException('Accès refusé.');
}
```

Conséquence concrète : **le propriétaire absent, la partie est bloquée pour tout le monde.** Les
autres participants voient un run en veille, un bouton qui ne leur est pas affiché, et aucun moyen
d'agir. C'est le premier symptôme cité par l'issue #387.

### Correction de cadrage : le bouton ne passe pas par `/runs/{id}/start`

**Constatée pendant l'implémentation, le 2026-08-15.** Le bouton « Reprendre manuellement » appelle
`POST /sessions/{sessionId}/restart`, donc `SessionLifecycleManager::initiateRestart()` - et **pas**
`PersonalRunLifecycle::start()`, que le cadrage initial de cette story désignait comme le point de
garde unique. La garde effective est le `isOwnedBy` de `SessionLifecycleManager` ligne 785.

`PersonalRunLifecycle::start()` reste une seconde route vers la même action, atteignable par l'API
même si l'interface ne l'emprunte pas depuis `idle`. Les deux sont traitées : une seule des deux
aurait produit un bouton qui marche et un appel d'API qui répond 403 pour la même chose.

### Décisions prises au cadrage (Jean, 2026-08-15)

| Question | Décision |
|---|---|
| Quelles actions s'ouvrent | **La relance depuis `STATUS_IDLE` uniquement.** `stop()` et le premier lancement depuis `STATUS_DRAFT` restent réservés au propriétaire. |
| Quels participants | **Tout participant du run**, qu'il ait déclaré des slots ou non. Pas de promotion, pas de rôle : le droit est acquis par l'appartenance au run. |

Le raisonnement derrière le périmètre minimal : relancer un run **déjà configuré et déjà lancé une
fois** ne décide de rien. Le premier lancement, lui, fige la configuration et les slots de tous les
participants ; l'arrêt coupe la partie des autres. Ces deux-là engagent autrui, la relance non.

### Rapport à #387

**Cette story ne referme pas #387 et ne doit pas être présentée comme telle.** L'issue demande un
mécanisme de **promotion co-owner** couvrant la configuration, les commandes host, l'invitation et
surtout le **spoiler** - dont la délégation est explicitement signalée comme le point le plus
sensible, puisqu'il dévoile tout le multiworld.

Ici, aucune promotion, aucun rôle, aucun accès nouveau à une information : un participant peut
rallumer un serveur déjà configuré, et rien d'autre. C'est précisément ce qui permet de livrer cette
story sans trancher une seule des décisions produit ouvertes de #387. À commenter sur l'issue plutôt
qu'à la fermer.

## Acceptance Criteria

### Autorisation côté API

1. **`POST /sessions/{sessionId}/restart` accepte un participant de la run privée liée.** C'est le
   chemin que le bouton emprunte réellement - voir la correction de cadrage ci-dessus - et donc le
   seul dont dépend la feature. La garde est dans `SessionLifecycleManager::initiateRestart()`.
2. `PersonalRunLifecycle::start()` accepte lui aussi un participant lorsque le run est en
   `STATUS_IDLE`, et le **refuse** en `STATUS_DRAFT`. Deux routes mènent à « reprendre ma run » ;
   les laisser diverger produirait un bouton qui marche et un appel d'API qui répond 403 sur la même
   action.
3. `stop()`, `setRecapVisibility()` et toutes les autres méthodes gardées par `isOwnedBy` sont
   **inchangées**. La délégation reste limitée à la reprise.
4. Un appelant qui n'est ni propriétaire ni participant reste refusé dans tous les cas.
5. Les validations existantes de `start()` s'appliquent identiquement au participant : run non déjà
   actif, statut démarrable, et **au moins un participant ayant des slots** (`games_required`). Le
   droit de relancer ne contourne aucune précondition.

### Contrat d'API et affichage

6. La charge utile du run expose un **drapeau dédié** (par exemple `canRestart`) indiquant si
   l'appelant courant peut relancer. **Il ne faut pas réutiliser `isOwner`** : le front garde une
   dizaine d'éléments sur ce booléen - onglet Réglages, override de configuration, lien
   d'invitation, renommage, overlay, spoiler - et le passer à `true` pour un participant lui
   ouvrirait tout cela d'un coup.
7. Le panneau de relance de `personal-run-detail-page.tsx` (bloc `IDLE`, bouton « Reprendre
   manuellement » / « Relancer depuis le début ») s'affiche pour un participant quand le drapeau est
   vrai. Le reste du bloc d'actions propriétaire reste gardé par `isOwner`.
8. Aucun autre panneau, onglet ou action n'apparaît pour le participant du fait de cette story. À
   vérifier écran par écran, pas à déduire du code.

### Traçabilité

9. La relance journalise **qui** l'a déclenchée quand ce n'est pas le propriétaire. Un run relancé
   par un tiers doit être explicable après coup, sans quoi le propriétaire constate un serveur
   rallumé sans savoir par qui. Un log applicatif suffit ; pas de nouvelle table.

### Gates

10. `composer gates` et `pnpm gates` verts. Tests fonctionnels : relance par le propriétaire (non
    régression), par un participant depuis `idle`, refus d'un participant depuis `draft`, refus d'un
    tiers non participant.

## Tasks / Subtasks

- [x] **Task 1** (AC 1-5). Autorisation sur les deux routes : `SessionLifecycleManager::initiateRestart()`
  (le chemin du bouton) et `PersonalRunLifecycle::start()`, avec la règle de domaine
  `Run::isStartAllowedFor()` et ses tests unitaires.
- [x] **Task 2** (AC 6). Drapeau `canStart` dans la charge utile, côté `PersonalRunDrafts`.
- [x] **Task 3** (AC 7-8). Carte de reprise extraite du bloc propriétaire, revue des autres panneaux.
- [x] **Task 4** (AC 9). Journalisation, sur `session.restart.initiated` et sur la route `/runs`.
- [x] **Task 5** (AC 10). Tests fonctionnels et gates des deux côtés.
- [ ] **Task 6.** Commenter #387 pour dire ce que cette story couvre et ce qu'elle laisse ouvert.
  **Ne pas fermer l'issue.**

## Dev Notes

- **Le piège de cette story : `start()` sert deux usages.** La même méthode couvre le premier
  lancement (`STATUS_DRAFT`) et la reprise (`STATUS_IDLE`) - c'est visible dans
  `$startableStatuses`. Relâcher la garde en tête de méthode ouvrirait donc **aussi** le premier
  lancement aux participants, ce que la décision de cadrage exclut. L'autorisation doit être
  évaluée en fonction du statut du run, et l'AC 2 existe pour que ce cas soit testé plutôt que
  supposé.
- **Ne pas mentir sur `isOwner`.** Le raccourci tentant est de renvoyer `isOwner: true` aux
  participants pour que le bouton apparaisse sans toucher au front. Il ouvrirait le spoiler, les
  réglages, le lien d'invitation et le renommage. D'où l'AC 6, et l'AC 8 qui demande une
  vérification écran par écran.
- **Asymétrie assumée : qui relance ne peut pas arrêter.** Un participant peut rallumer un run que
  le propriétaire venait d'arrêter, et seul le propriétaire peut le réarrêter. C'est voulu - le
  périmètre minimal était le critère - mais c'est à connaître, et c'est la première chose qui
  remontera si un propriétaire veut réellement garder son run éteint. Si le cas se présente, la
  réponse n'est pas d'élargir `stop()` : c'est #387 et sa notion de rôle.
- **La reprise reste manuelle par conception.** Ne pas profiter de cette story pour réintroduire un
  réveil à la connexion : il a été explicitement abandonné (stories 17.6 à 17.8).
- **Le coût réel d'une relance.** Elle redémarre un conteneur et consomme un port du pool. Ouvrir
  le droit multiplie les déclencheurs possibles ; rien n'indique que ce soit un problème à
  l'échelle actuelle, mais c'est la raison de l'AC 9.

### Project Structure Notes

- API, garde effective du bouton : `Sessions/Application/Service/SessionLifecycleManager.php`
  (`initiateRestart()`), atteinte par `Sessions/Presentation/Controller/SessionRestartController.php`.
- API : `PersonalRuns/Application/Command/PersonalRunLifecycle.php` (`start()`),
  `PersonalRuns/Application/Service/PersonalRunDrafts.php` (charge utile, `isOwner` calculé),
  `PersonalRuns/Domain/Repository/RunParticipantRepositoryInterface.php` (appartenance au run).
- Front : `frontend/src/features/personal-runs/personal-run-detail-page.tsx` (bloc d'actions gardé
  par `run.isOwner`, panneau `IDLE`), `types.ts` et `personal-runs-api.ts` pour le nouveau drapeau.
- Aucun changement dans `orchestrateur`, `bridge`, ni dans le contexte `Sessions`.

### References

- [Source: _bmad-output/planning-artifacts/epics/epic-16-personal-runs-private-user-created-archipelago-games.md]
- [Source: https://github.com/ArchiLAN-dev/archilan.fr/issues/387] - co-owner, sur-ensemble non couvert ici
- [Source: api/src/PersonalRuns/Application/Command/PersonalRunLifecycle.php] - point de garde unique
- [Source: api/CLAUDE.md] - AC-A3 (retour de commande), AC-P3/P4 (contrôleurs)
- [Source: frontend/AGENTS.md] - AC-TS3 (pas de cast à la frontière), AC-API1

## Dev Agent Record

### Agent Model Used

claude-opus-5

### Completion Notes List

**Le cadrage désignait le mauvais endpoint.** Écrit d'après `PersonalRunLifecycle::start()`, seule
méthode de cycle de vie gardée par `isOwnedBy` et manifestement « la » relance. Le bouton appelle en
réalité `POST /sessions/{sessionId}/restart`. Modifier `start()` seul aurait produit une story verte,
des tests verts, et **aucun changement pour le joueur**. Les deux routes sont traitées.

**La règle vit dans le Domaine, pour la route qui en a besoin.** `Run::isStartAllowedFor($userId,
$isParticipant)` encode « le propriétaire toujours, un participant seulement en veille ».
L'appartenance est passée en paramètre : le Domaine ne lit pas la base (AC-D3).

**Côté Sessions, la règle est volontairement plus simple, et ce n'est pas une divergence.**
`initiateRestart()` ne teste pas le statut de la run, parce qu'il a déjà exigé une session dans un
état relançable (`idle`/`stopped`/`crashed`). Le premier lancement y est donc impossible par
construction : une session existe, la partie a déjà tourné. Ajouter une condition de statut de run
aurait au contraire créé un faux négatif - une run restée `active` alors que sa session a crashé
hors bande n'aurait plus été relançable par un participant.

**`payload()` prend la participation en paramètre plutôt que de la deviner.** Trois de ses cinq
appelants lui passent `[]` comme liste de participants : dériver le drapeau de ce tableau aurait
produit un `canStart` faux en vue liste. Chaque appelant fournit maintenant un fait qu'il connaît
déjà - `listMine()` en particulier, où `owned` et `joined` *sont* la distinction, donc sans la
moindre requête supplémentaire et sans N+1.

**`isOwner` n'a pas été élargi**, malgré la tentation : le front garde une dizaine d'éléments dessus.
La carte de reprise a été **sortie** du bloc propriétaire, en y laissant le bouton « Supprimer la
partie » qui s'y trouvait aussi. La bannière d'erreur a été remontée d'un cran pour que le
participant voie l'échec de son action.

**Reste la Task 6** : commenter #387 sans la fermer.

### File List

- `api/src/PersonalRuns/Domain/Entity/Run.php` - `isStartAllowedFor()`
- `api/src/Sessions/Application/Service/SessionLifecycleManager.php` - garde de `initiateRestart()`, log
- `api/src/PersonalRuns/Application/Command/PersonalRunLifecycle.php` - garde de `start()`, log
- `api/src/PersonalRuns/Application/Service/PersonalRunDrafts.php` - `canStart`, 5 appelants de `payload()`
- `api/tests/Unit/PersonalRuns/RunStartAuthorizationTest.php` (nouveau) - 7 cas de la règle
- `api/tests/Functional/SessionRestartTest.php` - participant autorisé, participant d'une autre run refusé
- `api/tests/Functional/PersonalRunLifecycleTest.php` - les 4 cas de la route `/runs`
- `api/tests/Unit/Sessions/SessionLifecycleManagerRecordCrashTest.php` - nouvelle dépendance
- `frontend/src/features/personal-runs/personal-run-detail-page.tsx` - carte de reprise extraite
- `frontend/src/features/personal-runs/types.ts` - `canStart`

## Change Log

| Date | Change |
|------|--------|
| 2026-08-15 | Créée. Tranche étroite de #387 : la relance depuis `idle` ouverte à tout participant, sans mécanisme de promotion ni délégation du spoiler, donc sans avoir à trancher les décisions produit ouvertes de l'issue. Périmètre et éligibilité arbitrés avec Jean. |
