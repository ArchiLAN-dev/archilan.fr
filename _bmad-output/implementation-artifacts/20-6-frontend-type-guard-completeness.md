# Story 20.6: Type-Guard Completeness Audit + ESLint Assertion Enforcement

## Story

**As a** developer,
**I want** all API response parse sites to use type guard functions rather than `as` casts,
**So that** AC-TS3 from `AGENTS.md` is verified to be fully respected and cannot regress.

## Status

todo

## Acceptance Criteria

**AC1:** A grep audit of `frontend/src/features/**/*-api.ts` identifies every `as SomeType` cast applied to a value that originates from `response.json()`, `await fetch(...)`, or any other HTTP response. Every such cast is replaced by an `is{TypeName}(payload)` type guard in the same file.

**AC2:** Every `*-api.ts` file that parses a response exposes at least one `is{TypeName}(v: unknown): v is {TypeName}` guard function. The guard validates the shape minimally (checks presence of required keys and their primitive types) — not a full deep validation.

**AC3:** The ESLint rule `@typescript-eslint/consistent-type-assertions` is configured with `assertionStyle: "never"` scoped to `frontend/src/features/**/*-api.ts` files. This prevents future `as` casts in any API file.

**AC4:** `pnpm lint` exits 0 with 0 errors and 0 warnings.

**AC5:** `pnpm typecheck` and `pnpm build` remain clean (0 errors).

## Tasks / Subtasks

- [ ] Task 1: Create story file (this file)
- [ ] Task 2: Run grep audit for `as ` type assertions in `src/features/**/*-api.ts`
  - [ ] List each file, line, and the type being cast to
- [ ] Task 3: For each violation, write the corresponding type guard
  - [ ] Pattern: `function is{TypeName}(v: unknown): v is {TypeName} { return typeof v === "object" && v !== null && "fieldName" in v; }`
  - [ ] Replace `(await res.json()) as TypeName` with `const payload: unknown = await res.json(); if (!isTypeName(payload)) return null; return payload;`
- [ ] Task 4: Verify all `*-api.ts` files already follow the `return null` on non-OK response pattern (AC-API2)
- [ ] Task 5: Add `@typescript-eslint/consistent-type-assertions` rule to `eslint.config.*` scoped to `src/features/**/*-api.ts`
- [ ] Task 6: Run `pnpm lint` — resolve any newly surfaced violations
- [ ] Task 7: Run `pnpm typecheck` and `pnpm build` — verify clean

## Dev Notes

### Type guard minimal pattern

Type guards in `*-api.ts` files should validate the minimum shape needed to confidently use the data. They are not JSON Schema validators — they exist to narrow `unknown` to a typed value.

Use TypeScript 4.9+ `in`-operator narrowing so no `as` cast is needed inside the guard body. After `typeof v === "object" && v !== null`, TypeScript narrows `v` to `object`. After `"slug" in v`, it narrows to `object & Record<"slug", unknown>`, making `v.slug` accessible as `unknown` — no `as` required:

```ts
type PlayerProfile = {
  slug: string;
  displayName: string;
  totalGoals: number;
};

function isPlayerProfile(v: unknown): v is PlayerProfile {
  if (typeof v !== "object" || v === null) return false;
  return (
    "slug" in v && typeof v.slug === "string" &&
    "displayName" in v && typeof v.displayName === "string" &&
    "totalGoals" in v && typeof v.totalGoals === "number"
  );
}
```

This pattern uses no `as` cast and is therefore fully compatible with `assertionStyle: "never"` scoped to `*-api.ts` files. Next.js 15 requires TypeScript ≥ 5, which supports this narrowing.

### API envelope validation

Many API routes return `{ data: {...} }` envelopes. Guards must validate the envelope field as well as the payload shape:

```ts
function isPlayerProfileEnvelope(v: unknown): v is { data: PlayerProfile } {
  if (typeof v !== "object" || v === null) return false;
  return "data" in v && isPlayerProfile(v.data);
}

// Usage:
const payload: unknown = await res.json();
if (!isPlayerProfileEnvelope(payload)) return null;
return payload.data;
```

If an endpoint returns the object directly (no `data` wrapper), no envelope guard is needed — apply `isPlayerProfile` directly. Check the actual API contract for each endpoint before writing the guard.

### Fetch function pattern after migration

```ts
export async function fetchPlayerProfile(slug: string): Promise<PlayerProfile | null> {
  try {
    const res = await fetch(`${env.apiBaseUrl}/api/v1/players/${slug}`);
    if (!res.ok) return null;
    const payload: unknown = await res.json();
    if (!isPlayerProfile(payload)) return null;
    return payload;
  } catch {
    return null;
  }
}
```

### ESLint scoping

The `assertionStyle: "never"` rule is intentionally scoped to `*-api.ts` files only. Type assertions in component files (`as const`, `ref.current as HTMLInputElement`, etc.) are unaffected — those files are not covered by this rule override.

### What counts as an "API boundary" assertion in `*-api.ts` files

**Banned (by this rule):** Any `as SomeType` applied to:
- The result of `response.json()`
- A value typed as `unknown` or `any` that came from an HTTP call
- A destructured value from such a response

Note: `assertionStyle: "never"` also bans `as const` and `as unknown` within the scoped files. In practice, `*-api.ts` files have no legitimate reason to use `as const` (which belongs in type definition or constant files) or `as unknown` (which indicates a wrongly-typed value that a type guard would fix instead). If an API file genuinely needs a const assertion for a request payload shape, extract that constant to a `*-types.ts` file outside the scope of this rule.

## File List

- `frontend/src/features/**/*-api.ts` — type guard functions added; `as` casts removed (scope depends on audit)
- `frontend/eslint.config.*` — add `@typescript-eslint/consistent-type-assertions` scoped rule
- `_bmad-output/implementation-artifacts/20-6-frontend-type-guard-completeness.md` — this file

## Change Log

| Date       | Change         |
|------------|----------------|
| 2026-05-15 | Story created  |
