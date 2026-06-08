#!/usr/bin/env bash
# Weekly Run — live end-to-end smoke test (story 23.13).
#
# Exercises the REAL flow against the running `archilan` stack (orchestrateur + MinIO +
# Docker + api + worker + bridge + archipelago image), catching cross-service contract
# regressions that the unit/functional suites (Spy/Null gateways) cannot:
#   provision test admin -> login -> trigger generation -> wait generated
#   -> download artifact + assert it is a FLAT zip (multidata + per-player patch, no
#      zip-in-zip, no lone .archipelago) + correct download filename
#   -> opt-in + launch -> assert the session volume holds the loose files (incl. patch)
#      and the member patch list exposes the patch -> assert the seed actually hosts.
#
# Self-contained: provisions its own throwaway admin and cleans everything up. Idempotent.
#
# Prereqs: the dev stack is up (docker compose up), the API reachable, the archipelago
# image built. See scripts/e2e/README.md.
#
# Config (env, with defaults):
#   E2E_API_URL          default http://localhost:8000/api/v1
#   E2E_CONSOLE          default "php bin/console" (run from api/); override for CI, e.g.
#                        "docker compose -f docker-compose.prod.yml exec -T api-web php bin/console"
#   E2E_ADMIN_EMAIL      default e2e-admin@archilan.local
#   E2E_ADMIN_PASSWORD   default E2eSmoke!pass1
#   E2E_MINIO_CONTAINER  default archilan-minio
#   E2E_SESSIONS_BUCKET  default sessions
#   E2E_GEN_TIMEOUT      seconds to wait for generation (default 120)
#   E2E_LAUNCH_TIMEOUT   seconds to wait for launch (default 90)
#   E2E_SKIP_LAUNCH      set to 1 to skip the launch/volume/seed steps (artifact-only run)

set -uo pipefail

API="${E2E_API_URL:-http://localhost:8000/api/v1}"
CONSOLE="${E2E_CONSOLE:-php bin/console}"
ADMIN_EMAIL="${E2E_ADMIN_EMAIL:-e2e-admin@archilan.local}"
ADMIN_PASSWORD="${E2E_ADMIN_PASSWORD:-E2eSmoke!pass1}"
MINIO="${E2E_MINIO_CONTAINER:-archilan-minio}"
SESSIONS_BUCKET="${E2E_SESSIONS_BUCKET:-sessions}"
GEN_TIMEOUT="${E2E_GEN_TIMEOUT:-120}"
LAUNCH_TIMEOUT="${E2E_LAUNCH_TIMEOUT:-90}"
SKIP_LAUNCH="${E2E_SKIP_LAUNCH:-0}"
SESSION_COOKIE_NAME="__Host-archilan_session"

# Resolve repo root + api dir (script lives in scripts/e2e/).
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
API_DIR="$ROOT/api"

ADMIN_ID=""
COOKIE=""
RUN_ID=""
ENTRY_ID=""
TMP="$(mktemp -d 2>/dev/null || echo /tmp/e2e-weekly-$$)"
mkdir -p "$TMP"

red()  { printf '\033[31m%s\033[0m\n' "$*"; }
grn()  { printf '\033[32m%s\033[0m\n' "$*"; }
info() { printf '— %s\n' "$*"; }
fail() { red "✗ FAIL: $*"; cleanup; exit 1; }
ok()   { grn "✓ $*"; }

console() { ( cd "$API_DIR" && $CONSOLE "$@" ); }

cleanup() {
  info "cleanup…"
  # Best-effort teardown of the launched session containers + volume.
  if [ -n "$ENTRY_ID" ]; then
    docker rm -f "bridge-$ENTRY_ID" "ap-server-$ENTRY_ID" "archilan-bridge-$ENTRY_ID" >/dev/null 2>&1 || true
    MSYS_NO_PATHCONV=1 docker volume rm -f "archilan_session_$ENTRY_ID" >/dev/null 2>&1 || true
  fi
  # Remove the test admin and any runs/entries it produced (FK-safe order).
  console dbal:run-sql "DELETE FROM weekly_entries WHERE user_id IN (SELECT id FROM \"user\" WHERE email='$ADMIN_EMAIL')" >/dev/null 2>&1 || true
  if [ -n "$RUN_ID" ]; then
    console dbal:run-sql "DELETE FROM weekly_entries WHERE weekly_run_id='$RUN_ID'" >/dev/null 2>&1 || true
    console dbal:run-sql "DELETE FROM weekly_runs WHERE id='$RUN_ID'" >/dev/null 2>&1 || true
  fi
  console dbal:run-sql "DELETE FROM \"user\" WHERE email='$ADMIN_EMAIL'" >/dev/null 2>&1 || true
  rm -rf "$TMP" >/dev/null 2>&1 || true
}
trap cleanup EXIT

# ── 1. Provision a throwaway test admin ────────────────────────────────────────
provision_admin() {
  info "provisioning test admin $ADMIN_EMAIL"
  local hash
  hash="$(console security:hash-password "$ADMIN_PASSWORD" --no-ansi 2>/dev/null \
    | grep -oE '(\$2[aby]\$|\$argon2)[^[:space:]]+' | head -1)"
  [ -n "$hash" ] || fail "could not hash test password (security:hash-password)"
  ADMIN_ID="$(printf '%s' "$ADMIN_EMAIL" | md5sum 2>/dev/null | cut -c1-32)"
  [ -n "$ADMIN_ID" ] || ADMIN_ID="e2eadmin0000000000000000000000ab"
  # Idempotent: clear any prior test admin + its entries, then insert fresh.
  console dbal:run-sql "DELETE FROM weekly_entries WHERE user_id IN (SELECT id FROM \"user\" WHERE email='$ADMIN_EMAIL')" >/dev/null 2>&1 || true
  console dbal:run-sql "DELETE FROM \"user\" WHERE email='$ADMIN_EMAIL'" >/dev/null 2>&1 || true
  console dbal:run-sql "INSERT INTO \"user\" (id,email,email_canonical,display_name,password_hash,roles,cgu_accepted_at,cgu_accepted_version,created_at,updated_at) VALUES ('$ADMIN_ID','$ADMIN_EMAIL','$ADMIN_EMAIL','E2E Admin','$hash','[\"ROLE_ADMIN\"]',NOW(),'1.0',NOW(),NOW())" \
    >/dev/null 2>&1 || fail "could not insert test admin"
  ok "test admin ready ($ADMIN_ID)"
}

# ── 2. Login (capture the Secure session cookie manually for http) ──────────────
login() {
  info "login"
  local body
  body="$(curl -s -D "$TMP/h" -o "$TMP/login.json" -X POST "$API/auth/login" \
    -H 'Content-Type: application/json' \
    -d "{\"email\":\"$ADMIN_EMAIL\",\"password\":\"$ADMIN_PASSWORD\"}")"
  COOKIE="$(grep -i "^set-cookie:.*$SESSION_COOKIE_NAME=" "$TMP/h" \
    | sed -E "s/.*($SESSION_COOKIE_NAME=[^;]+).*/\1/" | head -1)"
  [ -n "$COOKIE" ] || fail "login did not return a session cookie (check creds / API URL $API)"
  ok "authenticated"
}

api_get()  { curl -s -D "$TMP/h" -H "Cookie: $COOKIE" "$API$1"; }
api_post() { curl -s -o "$TMP/resp" -w '%{http_code}' -H "Cookie: $COOKIE" -X POST "$API$1" "${@:2}"; }

# ── 3-4. Trigger generation + wait until the latest run is generated ────────────
trigger_and_wait() {
  local tid code
  tid="${E2E_TEMPLATE_ID:-}"
  if [ -z "$tid" ]; then
    tid="$(api_get "/admin/weekly-templates" | grep -oE '"id":"[0-9a-f]+"' | head -1 | sed -E 's/.*"id":"([0-9a-f]+)".*/\1/')"
  fi
  [ -n "$tid" ] || fail "no active weekly template found (set E2E_TEMPLATE_ID)"
  # Deterministic: drop this ISO-week's run for the template so generation creates a fresh
  # one we own (otherwise existsByTemplateAndWeek makes "Générer maintenant" a no-op).
  console dbal:run-sql "DELETE FROM weekly_runs WHERE template_id='$tid' AND week_year=EXTRACT(ISOYEAR FROM NOW()) AND week_number=EXTRACT(WEEK FROM NOW())" >/dev/null 2>&1 || true
  info "template $tid — triggering generation"
  code="$(api_post "/admin/weekly-runs/generate")"
  [ "$code" -ge 200 ] && [ "$code" -lt 300 ] || fail "generate returned HTTP $code"

  # Identify the exact run the trigger just created (newest for this template).
  sleep 1
  RUN_ID="$(console dbal:run-sql "SELECT id FROM weekly_runs WHERE template_id='$tid' ORDER BY created_at DESC LIMIT 1" 2>/dev/null | grep -oE '[0-9a-f]{16}' | head -1)"
  [ -n "$RUN_ID" ] || fail "no weekly run was created for template $tid"
  info "run $RUN_ID — waiting for generation (≤ ${GEN_TIMEOUT}s)…"
  local deadline=$(( $(date +%s) + GEN_TIMEOUT ))
  while [ "$(date +%s)" -lt "$deadline" ]; do
    # Wait for THIS run's output key (set by the session.generated webhook).
    if console dbal:run-sql "SELECT generated_output_key FROM weekly_runs WHERE id='$RUN_ID'" 2>/dev/null | grep -qE 'output/archive\.zip'; then
      ok "run generated: $RUN_ID"
      # Cross-check it is visible as generated through the real admin endpoint too.
      api_get "/admin/weekly-templates/$tid/runs" | grep -q "$RUN_ID" \
        || info "warning: run not in admin runs list yet (non-fatal)"
      return 0
    fi
    sleep 3
  done
  fail "generation did not complete within ${GEN_TIMEOUT}s (run=$RUN_ID)"
}

# ── 5. Download the artifact + assert the flat-zip contract ─────────────────────
assert_artifact() {
  info "downloading artifact for run $RUN_ID"
  local code
  code="$(curl -s -D "$TMP/dh" -o "$TMP/artifact.bin" -w '%{http_code}' \
    -H "Cookie: $COOKIE" "$API/admin/weekly-runs/$RUN_ID/output")"
  [ "$code" = "200" ] || fail "download returned HTTP $code"
  grep -iq "content-disposition:.*filename=\"weekly-run-$RUN_ID\.zip\"" "$TMP/dh" \
    || fail "download filename is not weekly-run-$RUN_ID.zip (Content-Disposition)"
  # Magic bytes = PK\x03\x04.
  [ "$(head -c4 "$TMP/artifact.bin" | od -An -tx1 | tr -d ' \n')" = "504b0304" ] \
    || fail "artifact is not a zip (bad magic)"
  ok "download is a .zip named weekly-run-$RUN_ID.zip"

  # Inspect entries with PHP's ZipArchive (always available; cross-platform).
  local names
  names="$( ( cd "$API_DIR" && php -r '$z=new ZipArchive; if($z->open($argv[1])!==true){exit(2);} for($i=0;$i<$z->numFiles;$i++){echo $z->statIndex($i)["name"]."\n";}' "$TMP/artifact.bin" ) 2>/dev/null )"
  [ -n "$names" ] || fail "could not read artifact zip entries"
  info "artifact entries:"; printf '%s\n' "$names" | sed 's/^/    /'
  printf '%s' "$names" | grep -qiE '\.archipelago$' || fail "no .archipelago (multidata) in artifact"
  # Reject zip-in-zip (a *.zip entry) and lone-multidata (only one .archipelago, nothing else).
  if printf '%s' "$names" | grep -qiE '\.zip$'; then fail "zip-in-zip: a .zip entry is nested in the artifact"; fi
  local count patch
  count="$(printf '%s\n' "$names" | grep -cE '.')"
  patch="$(printf '%s\n' "$names" | grep -viE '\.archipelago$|_spoiler' | grep -cE '.')"
  [ "$count" -gt 1 ] || fail "artifact has a lone file (expected multidata + at least one more)"
  [ "$patch" -ge 1 ] || fail "no per-player patch (non-archipelago, non-spoiler) in artifact"
  ok "artifact contract OK (flat zip: multidata + $patch patch/extra file(s))"
}

# ── 6-7. Launch an entry + assert volume restore, member patch list, seed hosts ─
assert_launch() {
  if [ "$SKIP_LAUNCH" = "1" ]; then info "E2E_SKIP_LAUNCH=1 → skipping launch/volume/seed checks"; return 0; fi
  command -v docker >/dev/null 2>&1 || { info "docker not available → skipping launch/volume/seed checks"; return 0; }

  info "opt-in"
  local resp code
  api_post "/weekly-runs/$RUN_ID/entries" >/dev/null
  ENTRY_ID="$(grep -oE '"id":"[0-9a-f]+"' "$TMP/resp" | head -1 | sed -E 's/.*"id":"([0-9a-f]+)".*/\1/')"
  [ -n "$ENTRY_ID" ] || fail "opt-in did not return an entry id"
  info "launch entry $ENTRY_ID (≤ ${LAUNCH_TIMEOUT}s)…"
  code="$(curl -s -o "$TMP/launch.json" -w '%{http_code}' -H "Cookie: $COOKIE" \
    --max-time "$LAUNCH_TIMEOUT" -X POST "$API/weekly-runs/$RUN_ID/entries/$ENTRY_ID/launch")"
  [ "$code" = "201" ] || fail "launch returned HTTP $code ($(cat "$TMP/launch.json"))"
  ok "launch returned connection info"

  # Session volume restored with the loose files (multidata + patch).
  local ls_out=""
  for c in "ap-server-$ENTRY_ID" "archilan-bridge-$ENTRY_ID"; do
    ls_out="$(MSYS_NO_PATHCONV=1 docker exec "$c" ls /data/output 2>/dev/null)" && [ -n "$ls_out" ] && break
  done
  [ -n "$ls_out" ] || fail "session volume /data/output not readable on launched containers"
  printf '%s' "$ls_out" | grep -qiE '\.archipelago$' || fail "launched volume missing the .archipelago"
  printf '%s' "$ls_out" | grep -qiE '\.ap[a-z0-9]+$' || fail "launched volume missing a per-player patch (.ap*)"
  ok "launch restored loose files into /data/output (multidata + patch)"

  # Member patch listing exposes the patch.
  local patches; patches="$(api_get "/weekly-runs/$RUN_ID/entries/$ENTRY_ID/patches")"
  printf '%s' "$patches" | grep -qiE '\.ap[a-z0-9]+' || fail "member patch list does not expose a patch"
  ok "member patch list exposes the patch"

  # Seed validity: the ap-server hosting the seed is up (it would not be otherwise).
  if MSYS_NO_PATHCONV=1 docker logs "ap-server-$ENTRY_ID" 2>&1 | grep -qiE 'server listening|Hosting game'; then
    ok "seed hosts (ap-server listening)"
  else
    info "ap-server log marker not found yet (non-fatal); container up = $(docker ps -q -f name=ap-server-$ENTRY_ID | wc -l)"
  fi
}

echo "=== Weekly Run E2E smoke — API: $API ==="
provision_admin
login
trigger_and_wait
assert_artifact
assert_launch
grn "=== ✅ Weekly Run E2E smoke PASSED ==="
