# Story 17.27: Port réservé par partie, rendu après 14 jours de pause

**Status:** ready-for-dev
**Epic:** 17 - Cycle de vie des sessions (inactivité, relance)
**Date:** 2026-10-01
**Dépôt :** `archilan-orchestrateur` (le monorepo n'est pas modifié)
**Dépend de :** 17.6 (inactivité gérée par l'orchestrateur), 17.26 (cohérence des ports)

## Story

En tant que joueur d'une partie mise en pause,
je veux que sa relance réutilise le même port qu'avant,
afin que mon client, mon mod ou mes réglages de connexion enregistrés fonctionnent encore sans rien changer.

## Contexte

Demande de Jean (2026-10-01). Aujourd'hui chaque lancement prend un nouveau port dans le pool de l'orchestrateur
(25000-25099, port Archipelago = port du pool + 10000) : `RelaunchFromSave` (reprise après pause) et
`RestartSession` (après crash) rappellent `Launch`, qui fait un `Acquire` neuf, et le port est rendu dès l'arrêt.
Une partie relancée change donc presque toujours d'adresse.

### Décisions prises avec Jean

- **L'orchestrateur reste seul à choisir le port.** La réservation est une préférence interne à son pool ; l'API
  continue d'apprendre le port par `session.ready` / `/restarted`, et Traefik de router ce que l'API lui sert.
  L'option « l'API choisit le port » est écartée (deux sources de vérité sur l'occupation).
- **Réservation glissante de 14 jours**, comptée depuis la mise en pause, plutôt qu'une purge hebdomadaire à date
  fixe : une purge le lundi ferait perdre son port à toute partie jouée un jour fixe par semaine (pause le samedi,
  purge le lundi, nouveau port le samedi suivant), soit le cas le plus courant.
- **Jamais de blocage** : si le pool est plein au lancement d'une partie, l'orchestrateur reprend le port de la
  **plus ancienne partie en pause** (Jean). Les réservations ne limitent donc jamais le nombre de parties ; la
  partie dépossédée prend un nouveau port à sa relance, comme aujourd'hui.

## Critères d'acceptation

1. **Pause** (`idleFromAutoShutdown`) : la partie garde son port, réservé jusqu'à la date de pause + la durée de
   réservation. Le port n'est plus rendu au pool.
2. **Relance** (`RelaunchFromSave`) : si la partie a encore sa réservation, elle reprend **le même port** ; sinon
   elle en prend un nouveau. Le comportement vu par l'API est inchangé (port communiqué par le même webhook).
3. **Crash** (`crashRunningSession`) puis `RestartSession` : même règle que la pause (voir décision D2).
4. **Expiration** : le sweeper existant rend au pool les réservations échues, à chaque passage. Aucune nouvelle
   tâche planifiée.
5. **Pool plein** : `Acquire` prend d'abord un port libre, sinon une réservation échue, sinon **la réservation la
   plus ancienne** (date de pause la plus lointaine). Le port n'est jamais retiré à une partie en cours.
6. **Suppression** (`DeleteSession`) : le port est rendu immédiatement.
7. **Redémarrage de l'orchestrateur** : `RecoverFromDB` recharge les réservations non échues avec leur date
   (colonne `port_reserved_until` sur `sessions`), en plus des ports des parties en cours.
8. **Réglage** : `PORT_RESERVATION_TTL` (défaut `336h`, soit 14 jours). `0` désactive la réservation et rend le
   comportement actuel (retour arrière sans release de code).
9. **Sûreté** : un port réservé n'est jamais lié par deux conteneurs. Les conteneurs d'une partie en pause sont
   supprimés et ceux d'une partie crashée arrêtés (ils ne tiennent plus le port hôte) ; la relance supprime les
   restes avant de démarrer. La protection `ReleaseFor` (ne libérer que si on est propriétaire) est conservée.
10. Tests du pool (préférence, expiration, reprise de la plus ancienne, jamais une partie en cours, rechargement)
    et des transitions ; `go test ./...` et le lint du dépôt passent.

## Décisions à trancher au démarrage

- **D1. Arrêt manuel** (`StopSession` appelé par l'API quand le propriétaire arrête sa partie) : réserver ou
  rendre ? Recommandation : **rendre**, l'arrêt manuel exprimant l'intention de finir. Attention :
  `RestartSession` appelle `StopSession` en nettoyage, ce chemin-là doit garder le port.
- **D2. Crash** : réserver comme une pause ? Recommandation : **oui**, la relance après crash est le cas où un
  joueur a le plus besoin de retrouver la même adresse.
- **D3. Capacité** : vérifier en production combien de parties sont en pause depuis moins de 14 jours. Au-delà
  d'une centaine de façon régulière, raccourcir la durée ou élargir la plage (pool, entrypoints Traefik,
  pare-feu). Requête indicative côté API :
  `SELECT count(*) FROM session WHERE status IN ('idle', 'stopped', 'crashed') AND stopped_at > now() - interval '14 days';`

## Tasks / Subtasks

- [ ] **Task 1** (AC 5, 8) - Pool : réservations avec échéance, `Acquire` avec préférence et règle de reprise ;
      tests.
- [ ] **Task 2** (AC 1-3, 6, 9) - Transitions pause / crash / relance / suppression ; tests.
- [ ] **Task 3** (AC 4) - Expiration dans le sweeper ; tests.
- [ ] **Task 4** (AC 7) - Colonne `port_reserved_until`, rechargement au démarrage ; tests.
- [ ] **Task 5** (AC 10) - Gates du dépôt, release de l'image orchestrateur.

## Hors périmètre (story séparée si souhaité)

- Afficher au joueur « adresse conservée jusqu'au JJ/MM » sur une partie en pause : demande que l'orchestrateur
  expose l'échéance et que l'API la relaie (client PHP de l'orchestrateur, monorepo, front).
