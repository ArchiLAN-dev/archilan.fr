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
  - [ ] 3b: Create `frontend/src/tests/constants.ts` exporting `TEST_API_BASE_URL = "http://localhost:8080"` — must contain only literal exports, no imports of any kind (application or infrastructure); this purity is what makes it safe to import before the `process.env` assignment in `setup.ts`
  - [ ] 3c: Create `frontend/src/tests/setup.ts` importing `TEST_API_BASE_URL` from `./constants`; assign to `process.env.NEXT_PUBLIC_API_BASE_URL`; configure MSW server
  - [ ] 3d: Add `setupFilesAfterEnv: ["<rootDir>/src/tests/setup.ts"]` to jest config
- [ ] Task 3e: Audit each `*-api.ts` file for its HTTP client before writing any test:
  ```bash
  rg 'fetch\(|apiFetch|axios|createClient|httpClient' frontend/src/features --glob '*-api.ts'
  ```
  For each file record whether it uses global `fetch` (→ MSW intercepts automatically) or a custom wrapper/axios (→ `jest.mock` the module instead). Files using global `fetch` follow the MSW pattern in Dev Notes; files using a custom client need a separate mocking strategy and must be noted before proceeding to Task 4.
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
import { TEST_API_BASE_URL } from "./constants";
process.env.NEXT_PUBLIC_API_BASE_URL = TEST_API_BASE_URL;

import { setupServer } from "msw/node";
export const server = setupServer();
beforeAll(() => server.listen({ onUnhandledRequest: "error" }));
afterEach(() => server.resetHandlers());
afterAll(() => server.close());
```

`constants.ts` exports only string literals and reads nothing from `process.env`, so importing it before the env assignment is safe. Application modules (`env.ts`, feature modules) must never be imported in `setup.ts`.

### Test pattern

```ts
// src/features/players/player-profile-api.test.ts
import { http, HttpResponse } from "msw";
import { server } from "../../tests/setup";
import { TEST_API_BASE_URL } from "../../tests/constants";
import { fetchPlayerProfile } from "./player-profile-api";

describe("fetchPlayerProfile", () => {
  it("returns profile on success", async () => {
    server.use(
      http.get(`${TEST_API_BASE_URL}/api/v1/players/jean`, () =>
        HttpResponse.json({ slug: "jean", displayName: "Jean", totalGoals: 5 })
      )
    );
    const result = await fetchPlayerProfile("jean");
    expect(result).not.toBeNull();
    expect(result?.slug).toBe("jean");
  });

  it("returns null on network error", async () => {
    server.use(http.get(`${TEST_API_BASE_URL}/api/v1/players/jean`, () => HttpResponse.error()));
    expect(await fetchPlayerProfile("jean")).toBeNull();
  });

  it("returns null when response fails type guard", async () => {
    server.use(http.get(`${TEST_API_BASE_URL}/api/v1/players/jean`, () => HttpResponse.json({ wrong: true })));
    expect(await fetchPlayerProfile("jean")).toBeNull();
  });
});
```

### MSW intercepts fetch only

MSW v2 in Node mode intercepts the global `fetch`. This test suite assumes every `*-api.ts` file uses the global `fetch` directly. If any file uses a custom HTTP client (e.g. `axios`, a wrapper library), MSW will not intercept its requests — identify such files before writing their tests and adapt the mocking strategy (e.g. mock the client module with `jest.mock`).

### Dependency on Story 20.6

Tests that call fetch functions rely on type guards being in place (Story 20.6). The malformed-response test directly exercises the guard. Story 20.6 should be completed before 20.7, or both can be done together — but the type guard must exist before the test can be written.

### env.ts in test context

Jest runs in Node. `setupFilesAfterEnv` scripts run **before** each test file's module is imported, so setting `process.env.NEXT_PUBLIC_API_BASE_URL` in `setup.ts` is safe.

Constraints for `constants.ts`:
- **No imports of any kind.** `constants.ts` must be a pure literal-export module. If it ever imports an application module (e.g. `env.ts`), that module executes during the import — before `process.env` is set in `setup.ts` — breaking the guarantee. Future contributors must not add imports to this file.

Constraints for `setup.ts`:
1. **Do not import application modules** (`env`, feature modules, etc.) in `setup.ts`. If an application module is imported in setup, Node's module cache loads it before the env var is set, potentially capturing `""` as `apiBaseUrl`.
2. Importing `./constants` is safe **only because** `constants.ts` has no imports of its own — if that invariant is violated, this import becomes unsafe too.

**Do not import `env` in tests to build base URLs.** Use `TEST_API_BASE_URL` from `src/tests/constants.ts` — it matches what `setup.ts` assigns to `process.env`. This avoids both the module-cache timing risk and the `string | undefined` type of `process.env.NEXT_PUBLIC_API_BASE_URL`. Do not use `testEnvironmentOptions.env` in `jest.config.ts` — that option has no effect on `NEXT_PUBLIC_*` variables in the `next/jest` pipeline.

## File List

- `frontend/jest.config.ts` — new: Jest configuration
- `frontend/src/tests/constants.ts` — new: `TEST_API_BASE_URL` constant (single source of truth for test base URL)
- `frontend/src/tests/setup.ts` — new: MSW server setup; imports `TEST_API_BASE_URL` from `./constants`
- `frontend/package.json` — add `"test": "jest"` script; add jest/msw devDependencies
- `frontend/src/features/**/*-api.test.ts` — new: one per existing `*-api.ts` file
- `_bmad-output/implementation-artifacts/20-7-frontend-jest-api-test-suite.md` — this file

## Change Log

| Date       | Change         |
|------------|----------------|
| 2026-05-15 | Story created  |
