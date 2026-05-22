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

# ── Auto-commit when all gates are green ──────────────────────────────────────
commit_line=""
if [ $fail -eq 0 ]; then
    uncommitted=$(git -C "$REPO_ROOT" status --short 2>/dev/null | wc -l | tr -d ' ')
    if [ "$uncommitted" -gt 0 ]; then
        dirs=$(git -C "$REPO_ROOT" status --short \
            | awk '{print $NF}' | cut -d/ -f1 | sort -u \
            | paste -sd ', ' -)
        commit_msg="chore(session): ${dirs} — quality gates ✅"
        git -C "$REPO_ROOT" add -A 2>/dev/null
        if git -C "$REPO_ROOT" commit -m "$commit_msg" 2>/dev/null; then
            sha=$(git -C "$REPO_ROOT" rev-parse --short HEAD 2>/dev/null)
            commit_line="📦 Commit : ${sha} — ${commit_msg}"
        else
            commit_line="⚠️ Auto-commit échoué (rien à committer ou hook bloquant)"
        fi
    fi
fi

msg="${header}\n\n${details}"
if [ -n "$commit_line" ]; then
    msg="${msg}${commit_line}\n"
fi
printf '{"systemMessage": %s}\n' \
    "$(printf '%s' "$msg" | python3 -c 'import json,sys; print(json.dumps(sys.stdin.read()))')"
