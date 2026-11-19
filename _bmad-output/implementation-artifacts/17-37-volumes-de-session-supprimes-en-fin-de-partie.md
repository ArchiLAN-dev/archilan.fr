# Story 17.37: Les volumes de session sont supprimés en fin de partie, la sauvegarde archivée

**Status:** review
**Epic:** 17 - Sessions
**Date:** 2026-11-19
**Origine :** nettoyage de prod du 2026-11-19 sur `ns310193` : 824 volumes de session orphelins (environ 5 par jour
depuis le 2026-06-05, 2,8 Go). 627 supprimés à la main (21 `archilan-prod_session_*` de l'ancien nommage, 606 de
sessions terminées ou absentes de la base), 203 gardés (parties en cours, en veille, plantées, weeklies récentes).
Les 557 `.apsave` sont archivés dans `/srv/backup/archilan-apsaves-20261119/apsaves-all.tgz`. Constat au passage :
`archived_save_path IS NOT NULL` vaut 0, l'archivage des sauvegardes n'a jamais marché.

## Story

En tant qu'administrateur d'ArchiLAN,
je veux qu'une partie terminée libère son volume Docker après que sa sauvegarde a été archivée dans MinIO,
afin que le disque du serveur ne grossisse plus sans fin, sans qu'un joueur perde l'avancement d'une partie qu'il peut
encore reprendre.

## Contexte

- Chaque session a un volume `archilan_session_<id>` (l'`<id>` est `session.id` côté API), monté dans
  `ap-server-<id>` et `archilan-bridge-<id>`.
- Le volume contient la sortie générée et la sauvegarde. La sortie (multidata, patchs, spoiler, `.archipelago`) est
  déjà dans MinIO depuis la génération (`<id>/output/archive.zip`). **Le `.apsave` n'existe que dans le volume.**
- Une mise en veille par `auto_shutdown` n'est **pas** une fin de partie : l'orchestrateur supprime les conteneurs
  mais garde le volume exprès (`idleFromAutoShutdown`), et `RelaunchFromSave` reprend sur ce volume. Un arrêt
  (`StopSession`) le garde aussi. L'orchestrateur ne distingue pas une veille d'une fin (tout est `stopped` chez
  lui) : la décision de supprimer revient à l'API, qui connaît l'état de la partie.
- Seules les weeklies demandent la suppression (`OrchestratorWeeklyRunnerGateway::terminate`, `DELETE /sessions/{id}`,
  qui supprime conteneurs, volume et enregistrement). Les runs perso et les sessions d'event ne la demandent jamais.
- `ArchiveRunJobHandler` cherche le `.apsave` dans `WORKSPACE_DIR/<id>/saves` (chemin de l'ancien runner, que l'API
  ne partage pas avec les volumes Docker) et le copie dans `ARCHIVE_DIR` : il ne trouve jamais rien. Les
  téléchargements `archivedSavePath` et `archivedSpoilerPath` de `DownloadController` répondent donc toujours vide.

## Critères d'acceptation

Révisés à l'implémentation (2026-11-19) : côté API, une session `stopped` (et même `finished`) peut encore être
relancée (`Session::ALLOWED_TRANSITIONS`). Décider qu'une partie est « finie » pour supprimer son volume aurait donc
fait perdre des parties reprenables. On ne décide plus : la sauvegarde est copiée dans MinIO à chaque arrêt, le volume
part au bout d'une durée d'inutilisation, et une relance le reconstruit. Plus de nouvel appel entre l'API et
l'orchestrateur, donc pas de nouvelle version de `archilan/orchestrateur-client`.

1. **Sauvegarde copiée à chaque arrêt** (orchestrateur) : arrêt, mise en veille par `auto_shutdown` et suppression
   copient le dernier `*.apsave` de `/data/output` vers MinIO (`<id>/save/latest.apsave`, nom d'origine en
   métadonnée), en flux : les patchs d'une grande partie ne sont pas chargés en mémoire. Un échec ne bloque pas
   l'arrêt.
2. **Ramassage des volumes** (orchestrateur, sweeper, au plus une fois par heure, 50 volumes par passage) : le volume
   d'une session `stopped` depuis plus de `SESSION_VOLUME_RETENTION` (7 jours par défaut, 0 = jamais) et celui d'une
   session inconnue de l'orchestrateur sont supprimés, sauvegarde copiée d'abord. Un volume dont la sauvegarde ne
   peut pas être copiée reste ; un volume encore monté (session plantée, qui garde ses conteneurs) est refusé par
   Docker et reste.
3. **Reconstruction à la relance** (orchestrateur) : `relaunch-from-save`, `restart` et `launch-from-file` sur un
   volume absent le reconstruisent depuis MinIO : sortie générée, yamls et mondes, puis la sauvegarde (sous son nom
   d'origine, ou celui du multidata avec `.apsave` pour une sauvegarde importée à la main). Sans sortie dans MinIO,
   la relance échoue proprement et la session reste `stopped`.
4. **API** : `ArchiveRunJobHandler` enregistre la clé MinIO de la sauvegarde ; les téléchargements admin de la
   sauvegarde et du spoiler lisent MinIO (le spoiler sort de l'archive générée, plus le multidata). `ARCHIVE_DIR` et
   le volume `api-archives`, qui ne servaient qu'à ça, sont retirés.
5. **Pas de régression de reprise** : une partie en veille garde son volume pendant la rétention et reprend comme
   avant ; au-delà, elle reprend avec son avancement depuis MinIO.
6. **Sauvegardes du 2026-11-19** : procédure documentée pour les copier dans MinIO (`<id>/save/latest.apsave`), ce
   qui rend leurs parties à nouveau reprenables avec leur avancement.
7. Gates vertes (API, orchestrateur) ; fonctions Docker vérifiées contre un vrai démon ; ordre de déploiement dans
   les notes de release.

## Tâches

- [x] Orchestrateur : copie de la sauvegarde, ramassage, reconstruction, tests (AC 1, 2, 3, 5)
- [x] API : job d'archivage et téléchargements sur MinIO, retrait d'`ARCHIVE_DIR` (AC 4)
- [x] Procédure pour les sauvegardes du 2026-11-19 (AC 6)
- [x] Gates, vérification Docker réelle (AC 7)

## Notes

- Volumes gardés lors du nettoyage du 2026-11-19 : sessions `idle`, `crashed`, `running`, `launching`, `generating`,
  `generated`, `restarting` ; runs `idle`, `active`, `starting`, `stopping`, `restarting` ; weeklies des 14 derniers
  jours. Les sessions `stopped` et `finished` supprimées ce jour-là restaient relançables côté API : avant la copie
  de leurs sauvegardes dans MinIO (AC 6), une relance repartirait de zéro.
- `WORKSPACE_DIR` sert encore à d'anciens chemins (patchs, yamls) : hors périmètre.
