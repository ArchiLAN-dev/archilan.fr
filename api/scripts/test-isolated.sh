#!/usr/bin/env bash
# Run the full phpunit suite against an ISOLATED Postgres test database.
#
# Why: FunctionalTestCase rebuilds the whole schema (DROP SCHEMA public CASCADE)
# on every test. Two phpunit processes sharing one database therefore destroy
# each other's schema mid-run - the "relation ... does not exist" mass-failure.
# Isolation is by database NAME via Symfony's TEST_TOKEN hook
# (config/packages/doctrine.yaml: dbname_suffix '_test%env(default::TEST_TOKEN)%'),
# same mechanism scripts/setup-worktree.sh uses per worktree.
# Full workflow and rationale: root CLAUDE.md, section "Sessions paralleles".
#
# This script exports TEST_TOKEN for its own process only - it does NOT write
# api/.env.test.local, so the tree's default behaviour is untouched afterwards.
#
# Usage (from api/, Git Bash on Windows works):
#   ./scripts/test-isolated.sh                    # DB archilan_test_local, full suite
#   ./scripts/test-isolated.sh mytoken            # DB archilan_test_mytoken
#   ./scripts/test-isolated.sh --testdox          # args starting with '-' go to phpunit
#   ./scripts/test-isolated.sh mytoken --testdox  # both
#
#   <name>  optional token, [a-z0-9-] (default: local). '-' becomes '_' in the
#           DB name: archilan_test_<name>.
#
# Cleanup (optional):
#   TEST_TOKEN=_<name> php bin/console doctrine:database:drop --env=test --force
set -euo pipefail

NAME="local"
if [[ $# -gt 0 && "$1" != -* ]]; then
  NAME="$1"
  shift
fi
[[ "$NAME" =~ ^[a-z0-9-]+$ ]] || { echo "==> ERROR: <name> must match [a-z0-9-] (got '$NAME')." >&2; exit 2; }

# TEST_TOKEN feeds a Postgres identifier: '-' -> '_' (same rule as setup-worktree.sh).
export TEST_TOKEN="_${NAME//-/_}"

# Run from api/ regardless of the caller's cwd.
cd "$(dirname "$0")/.."

echo "==> Test DB : archilan_test${TEST_TOKEN}  (TEST_TOKEN=${TEST_TOKEN})"
php bin/console doctrine:database:create --env=test --if-not-exists --quiet

php bin/phpunit "$@"
