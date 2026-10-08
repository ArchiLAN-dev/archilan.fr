# Story 23.15: Le lancement d'une hebdo adopte la session déjà lancée

**Status:** review
**Epic:** 23 - Weekly runs
**Date:** 2026-10-08

## Story

En tant que joueur d'une run hebdo,
je veux que mon lancement aboutisse même quand le serveur met plus d'une minute à démarrer,
afin de ne pas rester bloqué sur « launch_failed » avec un serveur qui tourne sans moi.

## Contexte

Constaté en prod le 2026-10-08 (entrée `adc4e7a63e96f913`) : le premier clic sur « Lancer » prend du temps puis
répond `launch_failed`, et chaque clic suivant répond `launch_failed` instantanément. Pendant ce temps,
`ap-server-<entryId>` et `archilan-bridge-<entryId>` tournent depuis le premier clic, inconnus de l'API (entrée
sans `external_session_id`).

Cause :

1. `OrchestratorWeeklyRunnerGateway` attend la session `running` **60 s**, l'orchestrateur s'accorde **120 s**
   (`LAUNCH_TIMEOUT`). En prod, l'écart entre la création du conteneur AP et celle du bridge (créé une fois l'AP
   prêt) tourne déjà autour d'une minute. Sur un démarrage de 60 à 120 s, l'API abandonne et n'enregistre rien,
   l'orchestrateur termine le lancement.
2. Au clic suivant, `configure` et `launch-from-file` refusent une session `running` (409 `already_in_progress`) :
   l'entrée ne se lance plus jamais.
3. `WeeklyRunLaunchController` convertit toute `RuntimeException` en `launch_failed` **sans journaliser** : la
   cause réelle est perdue.

Décision de Jean (2026-10-08) : correctif pour la prochaine release, pas en hotfix.

## Critères d'acceptation

1. **Adoption** : au lancement, si l'orchestrateur a déjà une session pour l'entrée en `running` (avec un port
   AP), la gateway l'adopte sans reconfigurer ni relancer : l'entrée est enregistrée avec ses infos de connexion
   comme pour un lancement normal. Une session encore en `launching` / `generating` est attendue puis adoptée.
   Sans session (404) ou dans tout autre état (`stopped`, `idle`, `crashed`, `pending`...), le lancement normal
   s'applique.
2. **Délai** : la gateway attend la session `running` au moins aussi longtemps que l'orchestrateur s'accorde
   pour un lancement (120 s), plus une marge.
3. **Traçabilité** : un échec de lancement est journalisé (warning) avec l'entrée, la run et le message de
   l'exception. La réponse HTTP reste `503 launch_failed`.
4. Tests : adoption d'une session `running`, attente puis adoption d'une session `launching`, lancement normal
   quand la session n'existe pas.

## Tâches

- [x] **Task 1** (AC 1, 2) - `OrchestratorWeeklyRunnerGateway` : `adoptExisting()` avant `configure`, délai 150 s.
- [x] **Task 2** (AC 3) - `WeeklyRunLaunchController` : log de l'échec.
- [x] **Task 3** (AC 4) - `OrchestratorWeeklyRunnerGatewayTest` (unitaire, `MockHttpClient`).
- [x] **Task 4** - `composer gates` vert.

## Dev Notes

- L'adoption vit dans la gateway (Infrastructure) : c'est elle qui parle à l'orchestrateur, et `launchEntry`
  garde son contrat (« une session `running` pour cette entrée, et ses infos de connexion »).
  `LaunchWeeklyEntry` n'a pas à changer : il enregistre l'entrée et la `Session` comme après un lancement.
- La session adoptée a été configurée et lancée par le premier clic avec la même config (même template, même
  sortie générée) : rien à refaire.
- Entrées déjà bloquées en prod : le premier clic après déploiement adopte leur serveur orphelin, sans action
  manuelle - tant que l'arrêt automatique pour inactivité ne l'a pas arrêté (session `idle` : lancement normal).
- PHP ne compte pas l'attente réseau ni `usleep` dans `max_execution_time` ; le frontend n'a pas de timeout sur
  cet appel. Le délai plus long ne coupe donc pas la requête.

## Dev Agent Record

### File List

- `api/src/WeeklyRuns/Infrastructure/Adapter/OrchestratorWeeklyRunnerGateway.php`
- `api/src/WeeklyRuns/Presentation/Controller/WeeklyRunLaunchController.php`
- `api/tests/Unit/WeeklyRuns/OrchestratorWeeklyRunnerGatewayTest.php`
