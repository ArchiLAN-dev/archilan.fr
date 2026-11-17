import { fetchSessionConnection } from "@/features/events/events-api";
import { fetchPersonalRun } from "@/features/personal-runs/personal-runs-api";
import { fetchCurrentWeeklyRuns } from "@/features/weekly-runs/weekly-runs-api";

import type { SlotRef } from "./slot-ref";

/**
 * Story 54.7: the connection of a slot, read again by the tracker page with the player's session. Only the existing
 * endpoints are used, and they answer only to whoever plays the slot: a slot of someone else resolves to `denied`.
 */
export type SlotCredentials = { address: string; slot: string; password: string };

export type SlotConnection =
  /** The party runs: the tracker connects. */
  | { kind: "ready"; gameSlug: string | null; credentials: SlotCredentials }
  /** Not launched (or paused): the tracker waits and reads again. */
  | { kind: "waiting"; gameSlug: string | null }
  /** Not this player's slot, or no such slot. */
  | { kind: "denied" }
  /** The API did not answer: try again later. */
  | { kind: "error" };

export async function fetchSlotConnection(ref: SlotRef): Promise<SlotConnection> {
  switch (ref.kind) {
    case "event":
      return eventSlot(ref.registrationId, ref.slotName);
    case "run":
      return runSlot(ref.runId, ref.slotName);
    case "weekly":
      return weeklySlot(ref.weeklyRunId);
  }
}

async function eventSlot(registrationId: string, slotName: string): Promise<SlotConnection> {
  const result = await fetchSessionConnection(registrationId);
  if (result.kind === "not_found") return { kind: "denied" };
  if (result.kind === "error") return { kind: "error" };
  const { session, slots } = result.data;
  const slot = slots.find((candidate) => candidate.slotName === slotName);
  // No session yet: its slots are not known, the registration is the player's own all the same.
  if (session === null) return { kind: "waiting", gameSlug: null };
  if (slot === undefined) return { kind: "denied" };
  if (session.status !== "running" || session.connectionUri === null) return { kind: "waiting", gameSlug: slot.gameSlug };
  return {
    kind: "ready",
    gameSlug: slot.gameSlug,
    credentials: { address: session.connectionUri, slot: slotName, password: session.password ?? "" },
  };
}

async function runSlot(runId: string, slotName: string): Promise<SlotConnection> {
  const result = await fetchPersonalRun(runId);
  if (result.kind === "not_found") return { kind: "denied" };
  if (result.kind === "error") return { kind: "error" };
  const { run } = result;
  // The API lists the caller's slots only while the run is active.
  if (run.status !== "active") return { kind: "waiting", gameSlug: null };
  const slot = (run.mySlots ?? []).find((candidate) => candidate.name === slotName);
  if (slot === undefined) return { kind: "denied" };
  const gameSlug = slot.gameSlug ?? null;
  const address = run.connectionUri ?? null;
  if (address === null) return { kind: "waiting", gameSlug };
  return { kind: "ready", gameSlug, credentials: { address, slot: slotName, password: run.connectionPassword ?? "" } };
}

async function weeklySlot(weeklyRunId: string): Promise<SlotConnection> {
  const runs = await fetchCurrentWeeklyRuns();
  const run = runs.find((candidate) => candidate.weeklyRunId === weeklyRunId);
  // An empty list is also what a network failure gives: say nothing worse than "not found".
  if (run === undefined || run.myEntry === null) return { kind: "denied" };
  const gameSlug = run.gameSlug ?? null;
  const entry = run.myEntry;
  const address = entry.connectionInfo?.uri ?? null;
  if (entry.sessionStatus !== "running" || address === null || !entry.slotName) return { kind: "waiting", gameSlug };
  return {
    kind: "ready",
    gameSlug,
    credentials: { address, slot: entry.slotName, password: entry.connectionInfo?.password ?? "" },
  };
}
