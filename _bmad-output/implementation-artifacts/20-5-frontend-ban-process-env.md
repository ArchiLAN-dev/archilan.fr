# Story 20.5: ESLint Rule — Ban process.env Outside env.ts

## Story

**As a** developer,
**I want** an ESLint rule that rejects any `process.env` access outside `src/lib/env.ts`,
**So that** AC-ENV1 from `AGENTS.md` is machine-enforced for all common access patterns (dot-access, computed, destructuring); optional chaining (`process?.env?.FOO`) is the one accepted gap, documented explicitly in Dev Notes.

## Status

review

## Acceptance Criteria

**AC1:** A grep audit of `frontend/src/**/*.{ts,tsx}` (excluding `src/lib/env.ts` and `**/*.test.{ts,tsx}`) identifies zero or more `process.env` accesses. Every occurrence found in non-test files is replaced by the appropriate `env.*` accessor from `src/lib/env.ts`. Test files intentionally use `process.env` for MSW base URL configuration (Story 20.7) and are excluded from the migration scope.

**AC2:** Three `no-restricted-syntax` selectors are added to `frontend/eslint.config.*`, together covering dot-access (`process.env.FOO`), computed access (`process["env"].FOO`), and destructuring (`const { FOO } = process.env`). Optional chaining (`process?.env?.FOO`) is explicitly accepted out-of-scope — it is not a realistic pattern in this codebase and has no straightforward single-selector equivalent. The rule is scoped to `src/**/*.{ts,tsx}` and excludes `src/lib/env.ts` and test files (`**/*.test.ts`, `**/*.test.tsx`) via the `ignores` field in the config block (see Dev Notes for exact config).

**AC3:** `pnpm lint` exits 0 with 0 errors and 0 warnings after the rule is added.

**AC4:** `pnpm typecheck` and `pnpm build` remain clean (0 errors).

## Tasks / Subtasks

- [x] Task 1: Create story file (this file)
- [x] Task 2: Run grep audit for all 4 access patterns (run from repo root):
  ```bash
  # Standard dot access (caught by ESLint selector 1)
  rg 'process\.env' frontend/src --glob '!frontend/src/lib/env.ts' --glob '!**/*.test.ts' --glob '!**/*.test.tsx'
  # Computed property (caught by ESLint selector 2)
  rg 'process\["env"\]' frontend/src --glob '!frontend/src/lib/env.ts' --glob '!**/*.test.ts' --glob '!**/*.test.tsx'
  # Destructuring — const/let/var (caught by ESLint selector 3)
  rg '(?:const|let|var)\s*\{[^}]+\}\s*=\s*process\.env' frontend/src --glob '!frontend/src/lib/env.ts' --glob '!**/*.test.ts' --glob '!**/*.test.tsx'
  # Optional chaining (NOT caught by ESLint — accepted out-of-scope; fix manually if found)
  rg 'process\?\.env' frontend/src --glob '!frontend/src/lib/env.ts' --glob '!**/*.test.ts' --glob '!**/*.test.tsx'
  ```
  - [x] Document each occurrence: file, line, pattern form, replacement
- [x] Task 3: Replace all violations with `env.*` accessors
  - [x] Add any missing env variables to `src/lib/env.ts` if the audit reveals accesses not yet covered
- [x] Task 4: Add ESLint `no-restricted-syntax` rule to `eslint.config.*` using the `ignores` field inside the config block (not a separate override block) to exclude `src/lib/env.ts` and test files — see Dev Notes for the exact config shape
- [x] Task 5: Run `pnpm lint` — fix any remaining issues
- [x] Task 6: Run `pnpm typecheck` and `pnpm build` — verify clean

## Dev Notes

### env.ts pattern

`src/lib/env.ts` is the canonical accessor. Any env variable accessed as `process.env.NEXT_PUBLIC_*` in client code or `process.env.*` in server code must be wrapped there:
```ts
// src/lib/env.ts
export const env = {
  apiBaseUrl: process.env.NEXT_PUBLIC_API_BASE_URL ?? "",
  // add new vars here
} as const;
```

### ESLint config format

The project uses the flat ESLint config format (`eslint.config.mjs` or `.js`). Scope the rule to `src/**` only (next.config.ts and build files legitimately use `process.env`), then override to allow it in `src/lib/env.ts`:
Use `ignores` inside the config block (not a separate override) to avoid accidentally disabling other `no-restricted-syntax` rules that may be added later:
```js
// eslint.config.mjs
export default [
  // ... other config blocks ...
  {
    files: ["src/**/*.{ts,tsx}"],
    // env.ts owns process.env; test files use process.env for MSW base URL setup
    ignores: ["src/lib/env.ts", "**/*.test.ts", "**/*.test.tsx"],
    rules: {
      "no-restricted-syntax": [
        "error",
        {
          // dot access: process.env.FOO
          selector: "MemberExpression[object.name='process'][property.name='env']",
          message: "Use src/lib/env.ts instead of process.env directly (AC-ENV1)."
        },
        {
          // computed access: process["env"].FOO
          selector: "MemberExpression[object.name='process'][computed=true][property.value='env']",
          message: "Use src/lib/env.ts instead of process[\"env\"] directly (AC-ENV1)."
        },
        {
          // destructuring: const/let/var { FOO } = process.env
          // VariableDeclarator is the same node for all declaration kinds;
          // "kind" (const/let/var) is on the parent VariableDeclaration, not the declarator
          selector: "VariableDeclarator[init.object.name='process'][init.property.name='env']",
          message: "Use src/lib/env.ts instead of destructuring process.env (AC-ENV1)."
        }
      ]
    }
  }
]
```

Using `ignores` within the block (instead of a separate `rules: {"no-restricted-syntax": "off"}` block) means that future restrictions added to `no-restricted-syntax` in other config blocks will still apply to `env.ts` — only this block's rules are excluded.

### ESLint selector coverage

Three selectors together cover all practical forms of `process.env` access:

| Pattern | Caught? | Selector |
|---------|---------|----------|
| `process.env.FOO` | Yes | `MemberExpression[object.name='process'][property.name='env']` |
| `process["env"].FOO` | Yes | `MemberExpression[object.name='process'][computed=true][property.value='env']` |
| `const/let/var { FOO } = process.env` | Yes | `VariableDeclarator[init.object.name='process'][init.property.name='env']` — `kind` is on the parent `VariableDeclaration`, not the `VariableDeclarator`; all declaration types are caught |
| `process?.env?.FOO` | No | Optional chaining — no straightforward single selector; explicitly accepted out-of-scope as this pattern is not realistic in this codebase |

The grep audit in Task 2 still runs all four patterns to clean up any existing optional chaining occurrences at story time.

### Next.js config files

`next.config.ts` / `next.config.js` live outside `src/` and legitimately use `process.env` during the build phase. The rule should be scoped to `src/**` only to avoid false positives on config files.

## File List

- `frontend/src/lib/env.ts` — potentially extended with new env vars found in audit
- `frontend/eslint.config.*` — add `no-restricted-syntax` rule + env.ts override
- Any `frontend/src/**/*.{ts,tsx}` files with `process.env` violations (identified by audit)
- `_bmad-output/implementation-artifacts/20-5-frontend-ban-process-env.md` — this file

## Dev Agent Record

### Completion Notes

Implemented 2026-05-15.

- Audit : zéro violation de `process.env` hors `src/lib/env.ts` — aucune correction requise sur le code existant.
- Ajout du bloc `no-restricted-syntax` dans `frontend/eslint.config.mjs` avec 3 sélecteurs (dot-access, computed, destructuring) scopés à `src/**/*.{ts,tsx}`, excluant `src/lib/env.ts` et les fichiers test via le champ `ignores` interne au bloc de config.
- Quality gates : `pnpm lint` 0 erreurs/avertissements, `pnpm typecheck` 0 erreurs, `pnpm build` propre.

## Change Log

| Date       | Change         |
|------------|----------------|
| 2026-05-15 | Story created  |
| 2026-05-15 | Implementation complete — ESLint rule added, quality gates green |
