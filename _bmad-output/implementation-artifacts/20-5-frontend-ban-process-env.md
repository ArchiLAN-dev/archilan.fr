# Story 20.5: ESLint Rule — Ban process.env Outside env.ts

## Story

**As a** developer,
**I want** an ESLint rule that rejects any `process.env` access outside `src/lib/env.ts`,
**So that** AC-ENV1 from `AGENTS.md` is machine-enforced and cannot be violated silently by future code.

## Status

todo

## Acceptance Criteria

**AC1:** A grep audit of `frontend/src/**/*.{ts,tsx}` (excluding `src/lib/env.ts`) identifies zero or more `process.env` accesses. Every occurrence found is replaced by the appropriate `env.*` accessor from `src/lib/env.ts`.

**AC2:** An ESLint rule is added to `frontend/eslint.config.*` that reports an error on any `MemberExpression` where the object is `process` and the property is `env`, in all files except `src/lib/env.ts`. Suggested form using `no-restricted-syntax`:
```js
{
  selector: "MemberExpression[object.name='process'][property.name='env']",
  message: "Access env vars through src/lib/env.ts, not process.env directly (AC-ENV1)."
}
```
with a file-level override that disables the rule inside `src/lib/env.ts`.

**AC3:** `pnpm lint` exits 0 with 0 errors and 0 warnings after the rule is added.

**AC4:** `pnpm typecheck` and `pnpm build` remain clean (0 errors).

## Tasks / Subtasks

- [ ] Task 1: Create story file (this file)
- [ ] Task 2: Run grep audit
  - [ ] Grep for `process\.env` in `frontend/src` excluding `src/lib/env.ts`
  - [ ] Document each occurrence: file, line, variable name, replacement
- [ ] Task 3: Replace all violations with `env.*` accessors
  - [ ] Add any missing env variables to `src/lib/env.ts` if the audit reveals accesses not yet covered
- [ ] Task 4: Add ESLint `no-restricted-syntax` rule to `eslint.config.*`
  - [ ] Add file override to disable the rule in `src/lib/env.ts` itself
- [ ] Task 5: Run `pnpm lint` — fix any remaining issues
- [ ] Task 6: Run `pnpm typecheck` and `pnpm build` — verify clean

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
```js
// eslint.config.mjs
export default [
  // ... other config blocks ...
  {
    files: ["src/**/*.{ts,tsx}"],
    rules: {
      "no-restricted-syntax": [
        "error",
        {
          selector: "MemberExpression[object.name='process'][property.name='env']",
          message: "Use src/lib/env.ts instead of process.env directly (AC-ENV1). Note: process[\"env\"] and destructuring are not caught by this rule — avoid those patterns too."
        }
      ]
    }
  },
  {
    files: ["src/lib/env.ts"],
    rules: { "no-restricted-syntax": "off" }
  }
]
```

### ESLint selector limitations

The `MemberExpression[object.name='process'][property.name='env']` selector catches the most common pattern but does **not** catch all forms of `process.env` access. The following patterns are **out of scope** for this rule:

| Pattern | Caught? | Notes |
|---------|---------|-------|
| `process.env.FOO` | Yes | Standard member access |
| `process["env"].FOO` | No | Computed property — AST has `computed: true` |
| `const { FOO } = process.env` | No | Destructuring — `process.env` is the `init`, not a MemberExpression with `property.name='env'` |
| `process?.env?.FOO` | No | Optional chaining — different AST node type |

These uncovered patterns are accepted limitations of the rule. The grep audit in Task 2 covers all four forms (`process\.env`, `process\["env"\]`, etc.) so the migration is complete even if the lint rule only enforces the common pattern going forward. Add a note to the rule's `message` string acknowledging this: "Also avoid `process["env"]` and `process.env` destructuring — use src/lib/env.ts."

### Next.js config files

`next.config.ts` / `next.config.js` live outside `src/` and legitimately use `process.env` during the build phase. The rule should be scoped to `src/**` only to avoid false positives on config files.

## File List

- `frontend/src/lib/env.ts` — potentially extended with new env vars found in audit
- `frontend/eslint.config.*` — add `no-restricted-syntax` rule + env.ts override
- Any `frontend/src/**/*.{ts,tsx}` files with `process.env` violations (identified by audit)
- `_bmad-output/implementation-artifacts/20-5-frontend-ban-process-env.md` — this file

## Change Log

| Date       | Change         |
|------------|----------------|
| 2026-05-15 | Story created  |
