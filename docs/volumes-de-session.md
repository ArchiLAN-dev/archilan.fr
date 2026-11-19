# Volumes de session

Story 17.37. Chaque session a un volume Docker `archilan_session_<id>` (l'`<id>` est celui de la session dans l'API),
monté dans `ap-server-<id>` et `archilan-bridge-<id>`.

## Ce qu'il contient, et où c'est gardé ailleurs

| Contenu | Ailleurs |
|---|---|
| Sortie générée (multidata, patchs, spoiler) | MinIO, `sessions/<id>/output/archive.zip`, dès la génération |
| Yamls et mondes | MinIO (`sessions/<id>/yamls/`, bucket `apworlds`) |
| Sauvegarde Archipelago (`.apsave`, l'avancement) | MinIO, `sessions/<id>/save/latest.apsave`, copiée à chaque arrêt |

## Cycle de vie

- **Arrêt, mise en veille (`auto_shutdown`), suppression** : l'orchestrateur copie la dernière sauvegarde dans MinIO.
- **Ramassage** (orchestrateur, au plus une fois par heure) : le volume d'une session arrêtée depuis plus de
  `SESSION_VOLUME_RETENTION` (7 jours par défaut) est supprimé, celui d'une session que l'orchestrateur ne connaît pas
  aussi. Sauvegarde copiée d'abord ; si la copie échoue, le volume reste. Un volume encore monté (session plantée, qui
  garde ses conteneurs pour les logs) reste.
- **Relance** (reprise, redémarrage, relance depuis le fichier) : un volume absent est reconstruit depuis MinIO, puis
  la sauvegarde est remise en place. La partie reprend avec son avancement.

Réglages (`envs/orchestrateur.env`) : `SESSION_VOLUME_RETENTION` en secondes (0 = ne jamais supprimer),
`VOLUME_COLLECT_INTERVAL` (3600 par défaut).

Téléchargements admin : `GET /api/v1/admin/sessions/{id}/download/save` et `.../download/spoiler` lisent MinIO.

## Réimporter les sauvegardes du nettoyage du 2026-11-19

Le nettoyage manuel du 2026-11-19 a supprimé 627 volumes ; leurs 557 sauvegardes sont dans
`/srv/backup/archilan-apsaves-20261119/apsaves-all.tgz` (un dossier par volume, `apsaves/<volume>/*.apsave`). Une
partie `stopped` ou `finished` de ce lot reste relançable côté API : sans sa sauvegarde dans MinIO, elle repartirait
de zéro. Copier chaque sauvegarde à sa place la rend à nouveau reprenable. Seules les sessions dont la sortie est
dans MinIO sont traitées (sans elle, aucune relance n'est possible de toute façon).

À lancer depuis le dossier de déploiement, une fois l'orchestrateur 0.28.0 en place. Vérifier d'abord la forme de
l'archive (`tar tzf ... | head`) : le script attend `apsaves/<volume>/<fichier>.apsave`, à adapter sinon.

```bash
mkdir -p /tmp/apsaves-import && tar xzf /srv/backup/archilan-apsaves-20261119/apsaves-all.tgz -C /tmp/apsaves-import
docker compose -f docker-compose.prod.yml run --rm --no-deps -v /tmp/apsaves-import:/import --entrypoint /bin/sh createbuckets -c '
  mc alias set local http://minio:9000 "$MINIO_ROOT_USER" "$MINIO_ROOT_PASSWORD" >/dev/null
  ok=0; skipped=0
  for dir in /import/apsaves/*/; do
    vol=$(basename "$dir"); id=${vol#archilan_session_}; id=${id#archilan-prod_session_}
    save=$(ls -t "$dir"*.apsave 2>/dev/null | head -1)
    if [ -z "$save" ] || ! mc stat "local/sessions/$id/output/archive.zip" >/dev/null 2>&1 \
       || mc stat "local/sessions/$id/save/latest.apsave" >/dev/null 2>&1; then
      skipped=$((skipped+1)); continue
    fi
    mc cp --quiet "$save" "local/sessions/$id/save/latest.apsave" >/dev/null && ok=$((ok+1))
  done
  echo "importees: $ok, ignorees: $skipped"
'
rm -rf /tmp/apsaves-import
```

Une sauvegarde déjà présente dans MinIO n'est pas écrasée : elle est plus récente que celle de l'archive. Le nom
d'origine n'est pas gardé ; à la relance, l'orchestrateur la nomme d'après le multidata, comme Archipelago.
