#!/usr/bin/env bash
# Transfer all archilan Docker volumes from local to production.
# Usage: ./scripts/transfer-volumes.sh
set -euo pipefail

REMOTE_HOST="debian@mithrix.fr"
REMOTE_PORT="5963"
REMOTE_DIR="/home/debian/docker/ArchiLAN/Websites/archilan.fr"
LOCAL_PREFIX="archilan_"
PROD_PREFIX="archilan-prod_"
TMP_DIR=$(mktemp -d)
SSH="ssh -p ${REMOTE_PORT}"
SCP="scp -P ${REMOTE_PORT}"

echo "==> Temporary directory: ${TMP_DIR}"
echo ""

# ─── Stop prod containers (to avoid partial reads on postgres/rabbitmq) ──────
echo "==> Stopping prod containers on remote..."
$SSH "${REMOTE_HOST}" "cd ${REMOTE_DIR} && docker compose -f docker-compose.prod.yml stop" || true

# ─── Export all local archilan volumes ───────────────────────────────────────
VOLUMES=$(docker volume ls --format "{{.Name}}" | grep "^${LOCAL_PREFIX}")

for LOCAL_VOL in $VOLUMES; do
    SUFFIX="${LOCAL_VOL#${LOCAL_PREFIX}}"
    PROD_VOL="${PROD_PREFIX}${SUFFIX}"
    ARCHIVE="${TMP_DIR}/${SUFFIX}.tar.gz"

    echo "==> Exporting ${LOCAL_VOL} → ${ARCHIVE}"
    docker run --rm \
        -v "${LOCAL_VOL}:/data" \
        -v "${TMP_DIR}:/backup" \
        alpine tar czf "/backup/${SUFFIX}.tar.gz" -C /data . 2>/dev/null || {
        echo "    [skip] Volume ${LOCAL_VOL} is empty or failed"
        continue
    }

    SIZE=$(du -sh "${ARCHIVE}" | cut -f1)
    echo "    Size: ${SIZE}"

    # ─── Transfer ─────────────────────────────────────────────────────────────
    echo "    Transferring to remote..."
    $SCP "${ARCHIVE}" "${REMOTE_HOST}:/tmp/${SUFFIX}.tar.gz"

    # ─── Create + import on remote ────────────────────────────────────────────
    echo "    Importing into ${PROD_VOL} on remote..."
    $SSH "${REMOTE_HOST}" "
        docker volume create ${PROD_VOL} 2>/dev/null || true
        docker run --rm \
            -v ${PROD_VOL}:/data \
            -v /tmp:/backup \
            alpine sh -c 'rm -rf /data/* /data/..?* /data/.[!.]* 2>/dev/null; tar xzf /backup/${SUFFIX}.tar.gz -C /data'
        rm -f /tmp/${SUFFIX}.tar.gz
    "
    echo "    Done: ${PROD_VOL}"
    echo ""
done

# ─── Cleanup ─────────────────────────────────────────────────────────────────
rm -rf "${TMP_DIR}"

# ─── Restart prod containers ─────────────────────────────────────────────────
echo "==> Restarting prod containers on remote..."
$SSH "${REMOTE_HOST}" "cd ${REMOTE_DIR} && docker compose -f docker-compose.prod.yml up -d"

echo ""
echo "✓ All volumes transferred successfully."
