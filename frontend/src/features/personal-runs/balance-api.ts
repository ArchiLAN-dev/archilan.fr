import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { hasBooleanProp, hasNullableNumberProp, hasNullableStringProp, hasNumberProp, hasStringProp } from "@/lib/type-guards";

/** Story 56.3: a dry generation's state. `none`: never generated. */
export type BalanceStatus = "none" | "generating" | "done" | "failed" | "needs_files";

export type BalanceAlert = "duration_over_median" | "duration_over_target" | "hosted_over_fair" | "hosted_over_half";

export type BalanceRow = {
  slotName: string;
  playerName: string;
  userId: string;
  gameName: string;
  measured: boolean;
  locations: number | null;
  hostedShare: number | null;
  fairShare: number | null;
  othersShare: number | null;
  hours: number | null;
  /** "measured" (our slots), "admin" (the game sheet), "unknown". */
  durationSource: string;
  medianGap: number | null;
  alerts: BalanceAlert[];
  /** Story 56.4: the slot's course in the party simulation; null without one. */
  simulation: SimulatedSlot | null;
};

/** Story 56.4: when a slot would end, its time in BK and the slots it waits on most. */
export type SimulatedSlot = {
  finishHours: number | null;
  bkHours: number | null;
  waitsOn: { slotName: string; hours: number | null }[];
  /** Its pace is unknown: played at the party's median pace. */
  paceAssumed: boolean;
};

/** Story 56.4: `missing` (no playthrough to read), `no_pace` (no slot has a known pace). */
export type PartySimulation = {
  status: "done" | "missing" | "no_pace";
  endHours: number | null;
  criticalSlot: string | null;
};

export type BalanceReport = {
  status: BalanceStatus;
  requestedAt: string | null;
  finishedAt: string | null;
  stale: boolean;
  error: string | null;
  missingFiles: string[];
  targetHours: number | null;
  medianHours: number | null;
  slotCount: number;
  /** Story 56.4: null in a player's own view. */
  simulation: PartySimulation | null;
  slots: BalanceRow[];
};

const STATUSES: readonly string[] = ["none", "generating", "done", "failed", "needs_files"];
const ALERTS: readonly string[] = ["duration_over_median", "duration_over_target", "hosted_over_fair", "hosted_over_half"];

function isStatus(value: unknown): value is BalanceStatus {
  return typeof value === "string" && STATUSES.includes(value);
}

function isAlert(value: unknown): value is BalanceAlert {
  return typeof value === "string" && ALERTS.includes(value);
}

const SIMULATION_STATUSES: readonly string[] = ["done", "missing", "no_pace"];

function isSimulationStatus(value: unknown): value is PartySimulation["status"] {
  return typeof value === "string" && SIMULATION_STATUSES.includes(value);
}

function parseSimulatedSlot(value: unknown): SimulatedSlot | null {
  if (typeof value !== "object" || value === null) return null;
  const waits = "waitsOn" in value && Array.isArray(value.waitsOn) ? value.waitsOn : [];
  return {
    finishHours: hasNullableNumberProp(value, "finishHours") ? value.finishHours : null,
    bkHours: hasNullableNumberProp(value, "bkHours") ? value.bkHours : null,
    waitsOn: waits.flatMap((w: unknown) =>
      typeof w === "object" && w !== null && hasStringProp(w, "slotName")
        ? [{ slotName: w.slotName, hours: hasNullableNumberProp(w, "hours") ? w.hours : null }]
        : [],
    ),
    paceAssumed: hasBooleanProp(value, "paceAssumed") ? value.paceAssumed : false,
  };
}

function parsePartySimulation(value: unknown): PartySimulation | null {
  if (typeof value !== "object" || value === null || !("status" in value) || !isSimulationStatus(value.status)) return null;
  return {
    status: value.status,
    endHours: hasNullableNumberProp(value, "endHours") ? value.endHours : null,
    criticalSlot: hasNullableStringProp(value, "criticalSlot") ? value.criticalSlot : null,
  };
}

function parseRow(value: unknown): BalanceRow | null {
  if (typeof value !== "object" || value === null) return null;
  if (!hasStringProp(value, "slotName") || !hasStringProp(value, "playerName") || !hasStringProp(value, "userId") || !hasStringProp(value, "gameName")) return null;
  if (!hasBooleanProp(value, "measured") || !hasNullableNumberProp(value, "locations")) return null;
  if (!hasNullableNumberProp(value, "hostedShare") || !hasNullableNumberProp(value, "fairShare") || !hasNullableNumberProp(value, "othersShare") || !hasNullableNumberProp(value, "medianGap")) return null;
  const duration = "duration" in value && typeof value.duration === "object" && value.duration !== null ? value.duration : null;
  const alerts = "alerts" in value && Array.isArray(value.alerts) ? value.alerts.filter(isAlert) : [];
  return {
    slotName: value.slotName,
    playerName: value.playerName,
    userId: value.userId,
    gameName: value.gameName,
    measured: value.measured,
    locations: value.locations,
    hostedShare: value.hostedShare,
    fairShare: value.fairShare,
    othersShare: value.othersShare,
    hours: duration !== null && hasNullableNumberProp(duration, "hours") ? duration.hours : null,
    durationSource: duration !== null && hasStringProp(duration, "source") ? duration.source : "unknown",
    medianGap: value.medianGap,
    alerts,
    simulation: "simulation" in value ? parseSimulatedSlot(value.simulation) : null,
  };
}

export function parseBalanceReport(json: unknown): BalanceReport | null {
  const data = typeof json === "object" && json !== null && "data" in json ? json.data : null;
  if (typeof data !== "object" || data === null || !("status" in data) || !isStatus(data.status)) return null;
  const status = data.status;
  const rows = "slots" in data && Array.isArray(data.slots) ? data.slots.map(parseRow).filter((row): row is BalanceRow => row !== null) : [];
  const missing = "missingFiles" in data && Array.isArray(data.missingFiles) ? data.missingFiles.filter((f): f is string => typeof f === "string") : [];
  return {
    status,
    requestedAt: hasNullableStringProp(data, "requestedAt") ? data.requestedAt : null,
    finishedAt: hasNullableStringProp(data, "finishedAt") ? data.finishedAt : null,
    stale: hasBooleanProp(data, "stale") ? data.stale : false,
    error: hasNullableStringProp(data, "error") ? data.error : null,
    missingFiles: missing,
    targetHours: hasNullableNumberProp(data, "targetHours") ? data.targetHours : null,
    medianHours: hasNullableNumberProp(data, "medianHours") ? data.medianHours : null,
    slotCount: hasNumberProp(data, "slotCount") ? data.slotCount : rows.length,
    simulation: "simulation" in data ? parsePartySimulation(data.simulation) : null,
    slots: rows,
  };
}

/** Where a scope's report lives: an event (admins), a private run (its organisers), or a player's own lines. */
export type BalanceScope = { kind: "event"; eventId: string } | { kind: "run"; runId: string } | { kind: "mine"; eventId: string };

function urlOf(scope: BalanceScope): string {
  if (scope.kind === "event") return `${env.apiBaseUrl}/admin/events/${scope.eventId}/balance`;
  if (scope.kind === "run") return `${env.apiBaseUrl}/runs/${scope.runId}/balance`;
  return `${env.apiBaseUrl}/events/${scope.eventId}/my-balance`;
}

/** null on any failure (a player not registered, a run they do not organise). */
export async function fetchBalanceReport(scope: BalanceScope): Promise<BalanceReport | null> {
  try {
    const res = await apiFetch(urlOf(scope), { cache: "no-store" });
    if (!res.ok) return null;
    return parseBalanceReport(await res.json());
  } catch {
    return null;
  }
}

/** null once asked, the API's message otherwise. */
export async function startBalanceGeneration(scope: Exclude<BalanceScope, { kind: "mine" }>): Promise<string | null> {
  try {
    const res = await apiFetch(urlOf(scope), { method: "POST" });
    if (res.ok) return null;
    const body: unknown = await res.json().catch(() => null);
    const error = typeof body === "object" && body !== null && "error" in body ? body.error : null;
    if (typeof error === "object" && error !== null && hasStringProp(error, "message")) return error.message;
    return "La génération à blanc n'a pas pu être lancée.";
  } catch {
    return "La génération à blanc n'a pas pu être lancée.";
  }
}
