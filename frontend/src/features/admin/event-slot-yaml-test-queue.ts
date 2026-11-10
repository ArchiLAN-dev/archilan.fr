import type { EventSlotYamlTestResult, EventSlotYamlTestStart } from "./event-slot-yaml-test-api";

/** Story 11.11: how many slot tests the page keeps in flight on the shared runner. */
export const MAX_PARALLEL_SLOT_TESTS = 3;
/** Between two reads of a running test. */
export const SLOT_TEST_POLL_MS = 4000;
/** About as long as the orchestrator keeps a result (thirty minutes). */
export const SLOT_TEST_MAX_POLLS = 450;

export type SlotTestState =
  | { kind: "idle" }
  | { kind: "queued" }
  | { kind: "running" }
  | { kind: "passed" }
  | { kind: "failed"; error: string }
  /** The game reads a file at generation: tested one by one, with the file. */
  | { kind: "needs_file"; requiredFiles: unknown }
  | { kind: "error"; message: string };

export type SlotTestSummary = { passed: number; failed: number; needsFile: number; pending: number; errors: number };

export function slotTestSummary(states: Record<string, SlotTestState>): SlotTestSummary {
  const summary: SlotTestSummary = { passed: 0, failed: 0, needsFile: 0, pending: 0, errors: 0 };
  for (const state of Object.values(states)) {
    if (state.kind === "passed") summary.passed += 1;
    else if (state.kind === "failed") summary.failed += 1;
    else if (state.kind === "needs_file") summary.needsFile += 1;
    else if (state.kind === "queued" || state.kind === "running") summary.pending += 1;
    else if (state.kind === "error") summary.errors += 1;
  }
  return summary;
}

/** « Tout tester » takes every slot again except those already in flight and those that wait for a file. */
export function slotsToTestAll(keys: string[], states: Record<string, SlotTestState>): string[] {
  return keys.filter((key) => {
    const kind = states[key]?.kind ?? "idle";
    return kind !== "queued" && kind !== "running" && kind !== "needs_file";
  });
}

/** The state a start leaves the slot in: running when the test started, otherwise its final word. */
export function stateAfterStart(start: EventSlotYamlTestStart): SlotTestState {
  if (start.kind === "started") return { kind: "running" };
  if (start.kind === "requires_file") return { kind: "needs_file", requiredFiles: start.requiredFiles };
  return { kind: "error", message: start.message };
}

/** The state a read leaves the slot in, or null while the test is still going. */
export function stateAfterPoll(result: EventSlotYamlTestResult): SlotTestState | null {
  if (result.kind === "gone") return { kind: "error", message: "Test introuvable ou expiré : relance-le." };
  if (result.kind === "error") return null;
  if (result.status === "passed") return { kind: "passed" };
  if (result.status === "failed") return { kind: "failed", error: result.error ?? "Échec de la génération." };
  return null;
}

/**
 * Runs `work` over the keys, `limit` at a time, in their order. Each worker takes the next key as soon as its own
 * is done, so one slow test never holds the others back. `alive` stops the pool from taking new keys.
 */
export async function runSlotTestPool(keys: string[], limit: number, work: (key: string) => Promise<void>, alive: () => boolean = () => true): Promise<void> {
  let next = 0;
  async function worker(): Promise<void> {
    while (next < keys.length && alive()) {
      const key = keys[next];
      next += 1;
      if (key !== undefined) await work(key);
    }
  }
  await Promise.all(Array.from({ length: Math.max(1, Math.min(limit, keys.length)) }, () => worker()));
}
