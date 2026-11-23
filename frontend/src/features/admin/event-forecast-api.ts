import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { hasBooleanProp, hasNullableNumberProp, hasNullableStringProp, hasNumberProp, hasStringProp } from "@/lib/type-guards";

/** Story 56.8: one slot of the running session, and when it should finish at its pace. */
export type ForecastSlot = {
  slotName: string;
  gameName: string;
  checksDone: number;
  locations: number | null;
  goalReached: boolean;
  released: boolean;
  checksPerHour: number | null;
  /** "observed" over the last hour of play, "estimated" from the game's pace, null without any pace. */
  paceSource: "observed" | "estimated" | null;
  finishAt: string | null;
  /** Progression items of the others still in this slot's world. */
  holdingProgression: number | null;
  toRelease: boolean;
};

export type CalibrationSlot = { slotName: string; gameName: string; estimatedHours: number; actualHours: number; gap: number };

export type EventForecast = {
  endsAt: string;
  live: { sessionId: string; slots: ForecastSlot[] } | null;
  calibration: { slots: CalibrationSlot[]; meanGap: number | null };
};

function isObject(value: unknown): value is object {
  return typeof value === "object" && value !== null;
}

export function isForecastSlot(value: unknown): value is ForecastSlot {
  return (
    isObject(value) &&
    hasStringProp(value, "slotName") &&
    hasStringProp(value, "gameName") &&
    hasNumberProp(value, "checksDone") &&
    hasNullableNumberProp(value, "locations") &&
    hasBooleanProp(value, "goalReached") &&
    hasBooleanProp(value, "released") &&
    hasNullableNumberProp(value, "checksPerHour") &&
    "paceSource" in value &&
    (value.paceSource === "observed" || value.paceSource === "estimated" || value.paceSource === null) &&
    hasNullableStringProp(value, "finishAt") &&
    hasNullableNumberProp(value, "holdingProgression") &&
    hasBooleanProp(value, "toRelease")
  );
}

export function isCalibrationSlot(value: unknown): value is CalibrationSlot {
  return (
    isObject(value) &&
    hasStringProp(value, "slotName") &&
    hasStringProp(value, "gameName") &&
    hasNumberProp(value, "estimatedHours") &&
    hasNumberProp(value, "actualHours") &&
    hasNumberProp(value, "gap")
  );
}

export function parseEventForecast(json: unknown): EventForecast | null {
  const data = isObject(json) && "data" in json ? json.data : null;
  if (!isObject(data) || !hasStringProp(data, "endsAt") || !("live" in data) || !("calibration" in data)) return null;

  let live: EventForecast["live"] = null;
  if (data.live !== null) {
    if (!isObject(data.live) || !hasStringProp(data.live, "sessionId") || !("slots" in data.live) || !Array.isArray(data.live.slots)) return null;
    live = { sessionId: data.live.sessionId, slots: data.live.slots.filter(isForecastSlot) };
  }
  const calibration = data.calibration;
  if (!isObject(calibration) || !("slots" in calibration) || !Array.isArray(calibration.slots) || !hasNullableNumberProp(calibration, "meanGap")) return null;

  return { endsAt: data.endsAt, live, calibration: { slots: calibration.slots.filter(isCalibrationSlot), meanGap: calibration.meanGap } };
}

/** Story 56.8: an event's live forecast and its calibration; null when unknown or unreachable. */
export async function getEventForecast(eventId: string): Promise<EventForecast | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/events/${encodeURIComponent(eventId)}/forecast`, { cache: "no-store" });
    if (!res.ok) return null;
    return parseEventForecast(await res.json());
  } catch {
    return null;
  }
}

/** Story 56.8: the comparison of a game's real and expected durations, from the admin game payload. */
export type DurationCalibration = { slots: number; meanGap: number };

export function asDurationCalibration(value: unknown): DurationCalibration | null {
  return isObject(value) && hasNumberProp(value, "slots") && hasNumberProp(value, "meanGap") ? { slots: value.slots, meanGap: value.meanGap } : null;
}
