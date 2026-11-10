import { requiredFilesForm } from "@/features/games/required-file-check";
import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { hasNullableStringProp, hasStringProp } from "@/lib/type-guards";

const START_ERROR = "Le test n'a pas pu être lancé. Réessaie dans un instant.";

/** Story 11.11: how starting the solo test of an event slot went. */
export type EventSlotYamlTestStart =
  | { kind: "started"; jobId: string }
  /** The game reads a file at generation (a ROM): the files it declares, to ask for before testing. */
  | { kind: "requires_file"; requiredFiles: unknown }
  | { kind: "error"; message: string };

export type EventSlotYamlTestResult =
  | { kind: "result"; status: "pending" | "running" | "passed" | "failed"; error: string | null }
  /** Unknown or expired: the orchestrator keeps a result thirty minutes. */
  | { kind: "gone" }
  | { kind: "error" };

function dataOf(json: unknown): unknown {
  return typeof json === "object" && json !== null && "data" in json ? json.data : null;
}

function errorOf(json: unknown): { code: string; message: string; details: unknown } | null {
  const error: unknown = typeof json === "object" && json !== null && "error" in json ? json.error : null;
  if (typeof error !== "object" || error === null || !hasStringProp(error, "code") || !hasStringProp(error, "message")) return null;
  return { code: error.code, message: error.message, details: "details" in error ? error.details : null };
}

export function parseEventSlotYamlTestStart(ok: boolean, json: unknown): EventSlotYamlTestStart {
  if (ok) {
    const data = dataOf(json);
    return typeof data === "object" && data !== null && hasStringProp(data, "jobId") ? { kind: "started", jobId: data.jobId } : { kind: "error", message: START_ERROR };
  }
  const error = errorOf(json);
  if (error === null) return { kind: "error", message: START_ERROR };
  if (error.code === "required_file") {
    const details = error.details;
    const requiredFiles: unknown = typeof details === "object" && details !== null && "requiredFiles" in details ? details.requiredFiles : [];
    return { kind: "requires_file", requiredFiles };
  }
  return { kind: "error", message: error.message };
}

export function parseEventSlotYamlTestResult(status: number, json: unknown): EventSlotYamlTestResult {
  if (status === 404) return { kind: "gone" };
  if (status < 200 || status >= 300) return { kind: "error" };
  const data = dataOf(json);
  if (typeof data !== "object" || data === null || !hasStringProp(data, "status") || !hasNullableStringProp(data, "error")) return { kind: "error" };
  const value = data.status;
  if (value !== "pending" && value !== "running" && value !== "passed" && value !== "failed") return { kind: "error" };
  return { kind: "result", status: value, error: data.error };
}

/**
 * Story 11.11: generate one slot of an event alone, with the YAML its registration holds. The file its game reads
 * (a ROM) travels as multipart when given.
 */
export async function startEventSlotYamlTest(
  eventId: string,
  registrationId: string,
  slotId: string,
  files: Record<string, File> = {},
): Promise<EventSlotYamlTestStart> {
  try {
    const body = Object.keys(files).length > 0 ? requiredFilesForm(files) : undefined;
    const res = await apiFetch(
      `${env.apiBaseUrl}/admin/events/${encodeURIComponent(eventId)}/registrations/${encodeURIComponent(registrationId)}/slots/${encodeURIComponent(slotId)}/yaml-test`,
      { method: "POST", body },
    );
    const json: unknown = await res.json().catch(() => null);
    return parseEventSlotYamlTestStart(res.ok, json);
  } catch {
    return { kind: "error", message: START_ERROR };
  }
}

/** Story 11.11: where a slot's test stands. */
export async function getEventSlotYamlTest(eventId: string, jobId: string): Promise<EventSlotYamlTestResult> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/events/${encodeURIComponent(eventId)}/yaml-tests/${encodeURIComponent(jobId)}`);
    const json: unknown = await res.json().catch(() => null);
    return parseEventSlotYamlTestResult(res.status, json);
  } catch {
    return { kind: "error" };
  }
}
