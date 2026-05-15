# Story 20.7: Jest Unit Test Suite for the API Layer

## Story

**As a** developer,
**I want** every `*-api.ts` file to have a corresponding Jest unit test file,
**So that** fetch logic, type guards, and error handling are verified independently of the browser and the running API.

## Status

todo

## Acceptance Criteria

**AC1:** Jest is installed and configured with the Next.js Jest preset (`next/jest`). A `jest.config.ts` (or `.js`) is added to `frontend/`. `pnpm test` is added to `package.json` scripts and runs the full test suite.

**AC2:** MSW (Mock Service Worker) v2 is installed for network-level fetch mocking. A `frontend/src/tests/setup.ts` file initialises the MSW server for tests and resets handlers between tests.

**AC3:** Each `src/features/**/*-api.ts` file has a sibling `*-api.test.ts` in the same directory. Each test file contains at minimum three test cases:
- **Happy path** — MSW returns a valid JSON response → the fetch function returns a correctly typed value (not `null`).
- **Network error** — `fetch` rejects (MSW configured to network-error) → the fetch function returns `null`.
- **Malformed response** — MSW returns `200` with JSON that fails the type guard → the fetch function returns `null`.

**AC4:** `pnpm test` exits 0 with all tests green.

**AC5:** `pnpm typecheck`, `pnpm lint`, and `pnpm build` remain clean (0 errors, 0 warnings).

## Tasks / Subtasks

- [ ] Task 1: Create story file (this file)
- [ ] Task 2: Install and configure Jest
  - [ ] 2a: `pnpm add -D jest @types/jest jest-environment-jsdom` (do NOT add `ts-jest` — `next/jest` handles TS transforms)
  - [ ] 2b: Create `jest.config.ts` using `createJestConfig` from `next/jest`
  - [ ] 2c: Add `"test": "jest"` to `package.json` scripts
- [ ] Task 3: Install and configure MSW v2
  - [ ] 3a: `pnpm add -D msw`
  - [ ] 3b: Create `frontend/src/tests/setup.ts` with server setup + `afterEach(server.resetHandlers)` + `process.env.NEXT_PUBLIC_API_BASE_URL` assignment
  - [ ] 3c: Add `setupFilesAfterEnv: ["<rootDir>/src/tests/setup.ts"]` to jest config
- [ ] Task 4: Write test files for each existing `*-api.ts` file — verify inventory with `rg --files frontend/src/features | rg -- '-api\.ts$'` before starting; the list below reflects the repo as of 2026-05-15
  - [ ] `src/features/events/public-events-api.test.ts`
  - [ ] `src/features/content/public-posts-api.test.ts`
  - [ ] `src/features/payments/membership-api.test.ts`
  - [ ] `src/features/payments/shop-api.test.ts`
  - [ ] `src/features/games/public-games-api.test.ts`
  - [ ] `src/features/games/game-request-api.test.ts`
  - [ ] `src/features/runs/run-results-api.test.ts`
  - [ ] `src/features/players/player-profile-api.test.ts`
  - [ ] `src/features/community/community-api.test.ts`
- [ ] Task 5: Run `pnpm test` — all green
- [ ] Task 6: Run `pnpm typecheck`, `pnpm lint`, `pnpm build` — all clean

## Dev Notes

### Jest config with Next.js

The Next.js Jest transformer handles `tsx`, `ts`, path aliases (`@/`), and CSS modules automatically. Do **not** install `ts-jest` — using both breaks the transform pipeline:
```ts
// jest.config.ts
import type { Config } from "jest";
import nextJest from "next/jest.js";

const createJestConfig = nextJest({ dir: "./" });

const config: Config = {
  testEnvironment: "jest-environment-jsdom",
  setupFilesAfterEnv: ["<rootDir>/src/tests/setup.ts"],  // NOT setupFilesAfterFramework
};

export default createJestConfig(config);
```

### MSW v2 setup

MSW v2 uses `http` instead of `rest` and `HttpResponse` instead of `ctx.json`:
```ts
// src/tests/setup.ts
import { setupServer } from "msw/node";
export const server = setupServer();
beforeAll(() => server.listen({ onUnhandledRequest: "error" }));
afterEach(() => server.resetHandlers());
afterAll(() => server.close());
```

### Test pattern

```ts
// src/features/players/player-profile-api.test.ts
import { http, HttpResponse } from "msw";
import { server } from "../../tests/setup";
import { fetchPlayerProfile } from "./player-profile-api";

// Hardcode the test base URL — process.env is string | undefined and would make ${BASE} unsafe
const BASE = "http://localhost:8080";

describe("fetchPlayerProfile", () => {
  it("returns profile on success", async () => {
    server.use(
      http.get(`${BASE}/api/v1/players/jean`, () =>
        HttpResponse.json({ slug: "jean", displayName: "Jean", totalGoals: 5 })
      )
    );
    const result = await fetchPlayerProfile("jean");
    expect(result).not.toBeNull();
    expect(result?.slug).toBe("jean");
  });

  it("returns null on network error", async () => {
    server.use(http.get(`${BASE}/api/v1/players/jean`, () => HttpResponse.error()));
    expect(await fetchPlayerProfile("jean")).toBeNull();
  });

  it("returns null when response fails type guard", async () => {
    server.use(http.get(`${BASE}/api/v1/players/jean`, () => HttpResponse.json({ wrong: true })));
    expect(await fetchPlayerProfile("jean")).toBeNull();
  });
});
```

### Dependency on Story 20.6

Tests that call fetch functions rely on type guards being in place (Story 20.6). The malformed-response test directly exercises the guard. Story 20.6 should be completed before 20.7, or both can be done together — but the type guard must exist before the test can be written.

### env.ts in test context

Jest runs in Node. `setupFilesAfterEnv` scripts run **before** each test file's module is imported, so setting `process.env.NEXT_PUBLIC_API_BASE_URL` in `setup.ts` is safe.

Two constraints for `setup.ts`:
1. The `process.env` assignment must be the **first statement** — before any `import`.
2. **Do not import application modules** (`env`, feature modules, etc.) in `setup.ts`. If an application module is imported in setup, Node's module cache loads it before the env var is set, potentially capturing `""` as `apiBaseUrl`. Only infrastructure packages (`msw/node`) are allowed in setup.

```ts
// src/tests/setup.ts
// 1. env vars first — before any import
process.env.NEXT_PUBLIC_API_BASE_URL = "http://localhost:8080";

// 2. only infrastructure imports
import { setupServer } from "msw/node";
export const server = setupServer();
beforeAll(() => server.listen({ onUnhandledRequest: "error" }));
afterEach(() => server.resetHandlers());
afterAll(() => server.close());
```

**Do not import `env` in tests to build base URLs.** Use the hardcoded `"http://localhost:8080"` string that `setup.ts` sets via `process.env`. This avoids both the module-cache timing risk and the `string | undefined` type of `process.env.NEXT_PUBLIC_API_BASE_URL`. Test files (`*.test.ts`) are excluded from Story 20.5's ESLint `no-restricted-syntax` rule (see Story 20.5 `ignores` configuration), but using a hardcoded constant is cleaner and type-safe:
```ts
const BASE = "http://localhost:8080"; // matches setup.ts assignment
server.use(
  http.get(`${BASE}/api/v1/players/jean`, () =>
    HttpResponse.json({ slug: "jean", displayName: "Jean", totalGoals: 5 })
  )
);
```

Do not use `testEnvironmentOptions.env` in `jest.config.ts` — that option has no effect on `NEXT_PUBLIC_*` variables in the `next/jest` pipeline.

## File List

- `frontend/jest.config.ts` — new: Jest configuration
- `frontend/src/tests/setup.ts` — new: MSW server setup
- `frontend/package.json` — add `"test": "jest"` script; add jest/msw devDependencies
- `frontend/src/features/**/*-api.test.ts` — new: one per existing `*-api.ts` file
- `_bmad-output/implementation-artifacts/20-7-frontend-jest-api-test-suite.md` — this file

## Change Log

| Date       | Change         |
|------------|----------------|
| 2026-05-15 | Story created  |
