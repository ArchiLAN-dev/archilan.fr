#!/usr/bin/env bash
# Quality gates runner — archilan.fr monorepo
# Called by the Claude Code Stop hook. Detects which directories have
# uncommitted changes and only runs the relevant gates.

REPO_ROOT="$(git rev-parse --show-toplevel 2>/dev/null)"
if [ -z "$REPO_ROOT" ]; then
    printf '{"systemMessage": "⚠️ Hors dépôt git — quality gates ignorés."}\n'
    exit 0
fi

# Directories with uncommitted changes (staged or unstaged)
changed=$(git -C "$REPO_ROOT" status --short 2>/dev/null \
    | awk '{print $NF}' \
    | cut -d/ -f1 \
    | sort -u)

if [ -z "$changed" ]; then
    printf '{"systemMessage": "✅ Aucun changement local — quality gates ignorés."}\n'
    exit 0
fi

pass=0
fail=0
details=""

gate() {
    local name="$1"
    local dir="$2"
    shift 2
    local output
    if output=$(cd "$dir" && "$@" 2>&1); then
        pass=$((pass + 1))
        details="${details}✅ ${name}\n"
    else
        fail=$((fail + 1))
        local last
        last=$(printf '%s' "$output" | tail -6)
        details="${details}❌ ${name}\n${last}\n\n"
    fi
}

has_change() { printf '%s\n' "$changed" | grep -qx "$1"; }

# ── API (Symfony / PHP) ────────────────────────────────────────────────────────
if has_change "api"; then
    D="$REPO_ROOT/api"
    gate "PHPStan"   "$D" vendor/bin/phpstan analyse src tests --no-progress
    gate "CS Fixer"  "$D" vendor/bin/php-cs-fixer check src
    gate "PHPUnit"   "$D" php bin/phpunit --no-coverage
    gate "DDD arch"  "$D" php bin/console app:architecture:ddd
fi

# ── Orchestrateur (Go) ────────────────────────────────────────────────────────
if has_change "orchestrateur"; then
    D="$REPO_ROOT/orchestrateur"
    gate "Go build"  "$D" go build ./...
    gate "Go vet"    "$D" go vet ./...
    gate "Go test"   "$D" go test ./...
fi

# ── Frontend (Next.js / TypeScript) ───────────────────────────────────────────
if has_change "frontend"; then
    D="$REPO_ROOT/frontend"
    gate "typecheck" "$D" pnpm typecheck
    gate "lint"      "$D" pnpm lint
fi

# ── Bridge (Python) ───────────────────────────────────────────────────────────
if has_change "bridge"; then
    D="$REPO_ROOT/bridge"
    gate "ruff"      "$D" python -m ruff check .
    gate "pytest"    "$D" python -m pytest
    gate "mypy"      "$REPO_ROOT" python -m mypy bridge/ --config-file bridge/pyproject.toml
fi

# ── Runner (Python) ───────────────────────────────────────────────────────────
if has_change "runner"; then
    D="$REPO_ROOT/runner"
    gate "ruff (runner)"   "$D" python -m ruff check .
    gate "pytest (runner)" "$D" python -m pytest
fi

# ── Report ────────────────────────────────────────────────────────────────────
if [ $((pass + fail)) -eq 0 ]; then
    printf '{"systemMessage": "ℹ️ Aucune quality gate applicable aux fichiers modifiés."}\n'
    exit 0
fi

if [ $fail -eq 0 ]; then
    header="✅ Quality gates : ${pass}/${pass} OK"
else
    header="❌ Quality gates : ${pass} OK, ${fail} EN ÉCHEC — corriger avant de continuer"
fi

# ── Ask Claude to run the git workflow when all gates are green ────────────────
git_ctx=""
if [ $fail -eq 0 ]; then
    uncommitted=$(git -C "$REPO_ROOT" status --short 2>/dev/null | wc -l | tr -d ' ')
    branch=$(git -C "$REPO_ROOT" branch --show-current 2>/dev/null)

    if [ "$uncommitted" -gt 0 ]; then
        case "$branch" in
            feature/*)
                git_ctx="Tous les quality gates sont verts. Il y a des changements non commités sur la branche feature '${branch}'. Effectue le workflow git : analyse les changements (git diff HEAD), crée des commits conventionnels logiques avec des messages descriptifs, push vers origin, puis ouvre une PR vers 'develop'."
                ;;
            hotfix/*)
                git_ctx="Tous les quality gates sont verts. Il y a des changements non commités sur la branche hotfix '${branch}'. Effectue le workflow git : crée des commits conventionnels, push vers origin, puis ouvre une PR vers 'main' ET une PR vers 'develop' (le hotfix doit merger dans les deux)."
                ;;
            release/*)
                git_ctx="Tous les quality gates sont verts. Il y a des changements non commités sur la branche release '${branch}'. Effectue le workflow git : crée des commits conventionnels, push vers origin, puis ouvre une PR vers 'main' et une PR vers 'develop'."
                ;;
            develop)
                git_ctx="Tous les quality gates sont verts. Il y a des changements non commités sur 'develop'. Rappel Gitflow : 'develop' ne doit recevoir que des merges de branches feature/hotfix/release, pas de commits directs. Si ce travail correspond à une story BMAD, crée une branche feature/* et commite là-bas."
                ;;
            main)
                git_ctx="ATTENTION : tu es sur 'main' avec des changements non commités. Selon le Gitflow du projet, 'main' ne reçoit que des merges de release/* ou hotfix/* via PR. Ne commite pas directement sur main — crée une branche appropriée."
                ;;
            *)
                git_ctx="Tous les quality gates sont verts. Il y a des changements non commités sur la branche '${branch}'. Effectue le workflow git : analyse les changements (git diff HEAD), crée des commits conventionnels avec des messages descriptifs, puis push vers origin."
                ;;
        esac
    fi
fi

# ── Emit JSON (systemMessage + optional additionalContext) ─────────────────────
msg="${header}\n\n${details}"
printf '%s' "$msg" | GATE_CTX="$git_ctx" python3 -c '
import json, sys, os
msg = sys.stdin.read()
ctx = os.environ.get("GATE_CTX", "")
output = {"systemMessage": msg}
if ctx:
    output["hookSpecificOutput"] = {
        "hookEventName": "Stop",
        "additionalContext": ctx
    }
print(json.dumps(output))
'
