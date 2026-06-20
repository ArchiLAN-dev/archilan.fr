#!/usr/bin/env bash
# Set up an isolated git worktree for a parallel agent / dev session.
#
# Each worktree gets its own working tree (no checkout collisions, no shared
# stash) and its own Postgres test database via Symfony's TEST_TOKEN hook
# (see api/config/packages/doctrine.yaml: dbname_suffix '_test%env(default::TEST_TOKEN)%').
# Everything else (Postgres server, Docker, MinIO, dev servers) stays shared.
#
# Usage:
#   ./scripts/setup-worktree.sh <name> [branch] [--base <branch>] [--no-frontend]
#
#   <name>          short token, e.g. "avatar" — used for the worktree dir
#                   (../archilan-<name>) and the test DB suffix (archilan_test_<name>).
#   [branch]        branch to check out. Defaults to "feature/<name>".
#                   - existing local branch        -> checked out as-is
#                   - existing origin/<branch>      -> local tracking branch created
#                   - otherwise                     -> new branch from --base
#   --base <branch> base for a NEW branch (default: develop)
#   --no-frontend   skip `pnpm install` (use when the agent only touches api/)
#
# Examples:
#   ./scripts/setup-worktree.sh avatar feature/epic-30-story-27-avatar-upload
#   ./scripts/setup-worktree.sh hotfix-jwt --base main
set -euo pipefail

# --- parse args -------------------------------------------------------------
NAME=""
BRANCH=""
BASE="develop"
INSTALL_FRONTEND=1

while [[ $# -gt 0 ]]; do
  case "$1" in
    --base)        BASE="${2:?--base needs a value}"; shift 2 ;;
    --no-frontend) INSTALL_FRONTEND=0; shift ;;
    -h|--help)     sed -n '2,30p' "$0"; exit 0 ;;
    --*)           echo "Unknown option: $1" >&2; exit 2 ;;
    *)
      if [[ -z "$NAME" ]]; then NAME="$1"
      elif [[ -z "$BRANCH" ]]; then BRANCH="$1"
      else echo "Unexpected argument: $1" >&2; exit 2
      fi
      shift ;;
  esac
done

[[ -n "$NAME" ]] || { echo "==> ERROR: <name> is required." >&2; sed -n '8,11p' "$0" >&2; exit 2; }
[[ "$NAME" =~ ^[a-z0-9-]+$ ]] || { echo "==> ERROR: <name> must match [a-z0-9-] (got '$NAME')." >&2; exit 2; }
BRANCH="${BRANCH:-feature/$NAME}"

# TEST_TOKEN feeds a Postgres identifier: lowercase, '-' -> '_'.
TOKEN="_${NAME//-/_}"

# --- locate the MAIN worktree (robust even if run from a linked worktree) ---
git rev-parse --is-inside-work-tree >/dev/null 2>&1 || {
  echo "==> ERROR: not inside a git repository." >&2; exit 1; }
MAIN_ROOT="$(git worktree list --porcelain | awk '/^worktree /{print $2; exit}')"
PARENT_DIR="$(dirname "$MAIN_ROOT")"
WT_DIR="$PARENT_DIR/archilan-$NAME"

[[ ! -e "$WT_DIR" ]] || { echo "==> ERROR: '$WT_DIR' already exists." >&2; exit 1; }

echo "==> Worktree   : $WT_DIR"
echo "==> Branch     : $BRANCH"
echo "==> Test DB    : archilan_test${TOKEN}  (TEST_TOKEN=${TOKEN})"

# --- resolve the branch & create the worktree -------------------------------
git fetch origin --quiet || echo "==> WARN: git fetch failed (offline?), continuing with local refs."

if git show-ref --verify --quiet "refs/heads/$BRANCH"; then
  echo "==> Using existing local branch."
  git worktree add "$WT_DIR" "$BRANCH"
elif git show-ref --verify --quiet "refs/remotes/origin/$BRANCH"; then
  echo "==> Creating local tracking branch from origin/$BRANCH."
  git worktree add --track -b "$BRANCH" "$WT_DIR" "origin/$BRANCH"
else
  echo "==> Creating new branch '$BRANCH' from '$BASE'."
  git show-ref --verify --quiet "refs/heads/$BASE" \
    || git show-ref --verify --quiet "refs/remotes/origin/$BASE" \
    || { echo "==> ERROR: base branch '$BASE' not found locally or on origin." >&2; exit 1; }
  git worktree add -b "$BRANCH" "$WT_DIR" "$BASE"
fi

# --- per-worktree test DB isolation (1 line, gitignored) --------------------
ENV_TEST_LOCAL="$WT_DIR/api/.env.test.local"
if [[ ! -f "$ENV_TEST_LOCAL" ]]; then
  cat > "$ENV_TEST_LOCAL" <<EOF
# Worktree-local override (gitignored). Isolates this worktree's phpunit
# database so parallel sessions don't clobber each other's schema/fixtures.
# Doctrine builds the name as: <db>_test<TEST_TOKEN> -> archilan_test${TOKEN}
TEST_TOKEN=${TOKEN}
EOF
  echo "==> Wrote $ENV_TEST_LOCAL"
else
  echo "==> $ENV_TEST_LOCAL already exists, left untouched."
fi

# --- install dependencies (node_modules / vendor are per-worktree) ----------
echo "==> composer install (api)…"
( cd "$WT_DIR/api" && composer install --no-interaction )

if [[ "$INSTALL_FRONTEND" -eq 1 ]]; then
  echo "==> pnpm install (frontend)…"
  ( cd "$WT_DIR/frontend" && pnpm install )
else
  echo "==> Skipping pnpm install (--no-frontend)."
fi

# --- create the isolated test database --------------------------------------
echo "==> Creating test database archilan_test${TOKEN}…"
( cd "$WT_DIR/api" && php bin/console doctrine:database:create --env=test --if-not-exists )

echo
echo "==> Done. Open a terminal in: $WT_DIR"
echo "    Quality gates run there against an isolated DB and working tree."
echo "    When finished:  git worktree remove $WT_DIR"
echo "    (drop the DB too if you want:  php bin/console doctrine:database:drop --env=test --force)"
