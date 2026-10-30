import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { hasBooleanProp, hasNullableStringProp, hasStringProp } from "@/lib/type-guards";

/** Story 46.1: what a note can be written on. A closed list, as on the API. */
export type NoteTargetType = "registration";

export type NoteAuthor = { id: string; displayName: string | null };

export type FollowUpKind = "comment" | "settled" | "reopened";

export type NoteFollowUp = { id: string; kind: FollowUpKind; body: string; author: NoteAuthor; createdAt: string };

/** Story 46.2: an admin note and its timeline, as the panel reads them. Only admins ever see one. */
export type AdminNote = {
  id: string;
  body: string;
  status: "open" | "settled";
  author: NoteAuthor;
  createdAt: string;
  updatedAt: string;
  edited: boolean;
  followUps: NoteFollowUp[];
};

export type NoteRevision = { id: string; previousBody: string; editor: NoteAuthor; editedAt: string };

export const NOTE_MAX_LENGTH = 2000;

export function adminNotesKey(targetType: NoteTargetType, targetId: string) {
  return ["admin-notes", targetType, targetId] as const;
}

function isObject(v: unknown): v is object {
  return typeof v === "object" && v !== null;
}

export function isNoteAuthor(v: unknown): v is NoteAuthor {
  return isObject(v) && hasStringProp(v, "id") && hasNullableStringProp(v, "displayName");
}

export function isNoteFollowUp(v: unknown): v is NoteFollowUp {
  return (
    isObject(v) &&
    hasStringProp(v, "id") &&
    hasStringProp(v, "kind") &&
    ["comment", "settled", "reopened"].includes(v.kind) &&
    hasStringProp(v, "body") &&
    "author" in v &&
    isNoteAuthor(v.author) &&
    hasStringProp(v, "createdAt")
  );
}

export function isAdminNote(v: unknown): v is AdminNote {
  return (
    isObject(v) &&
    hasStringProp(v, "id") &&
    hasStringProp(v, "body") &&
    hasStringProp(v, "status") &&
    (v.status === "open" || v.status === "settled") &&
    "author" in v &&
    isNoteAuthor(v.author) &&
    hasStringProp(v, "createdAt") &&
    hasStringProp(v, "updatedAt") &&
    hasBooleanProp(v, "edited") &&
    "followUps" in v &&
    Array.isArray(v.followUps) &&
    v.followUps.every(isNoteFollowUp)
  );
}

export function isNoteRevision(v: unknown): v is NoteRevision {
  return isObject(v) && hasStringProp(v, "id") && hasStringProp(v, "previousBody") && "editor" in v && isNoteAuthor(v.editor) && hasStringProp(v, "editedAt");
}

function dataOf(payload: unknown): unknown {
  return isObject(payload) && "data" in payload ? payload.data : undefined;
}

/** The notes of a target, open ones first; null when the read failed. */
export async function fetchAdminNotes(targetType: NoteTargetType, targetId: string): Promise<AdminNote[] | null> {
  const query = new URLSearchParams({ targetType, targetId });
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/notes?${query.toString()}`);
    if (!res.ok) return null;
    const data = dataOf(await res.json());
    return Array.isArray(data) ? data.filter(isAdminNote) : null;
  } catch {
    return null;
  }
}

/** The previous texts of a note, newest first; null when the read failed. */
export async function fetchNoteRevisions(noteId: string): Promise<NoteRevision[] | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/notes/${noteId}/revisions`);
    if (!res.ok) return null;
    const data = dataOf(await res.json());
    return Array.isArray(data) ? data.filter(isNoteRevision) : null;
  } catch {
    return null;
  }
}

/** null on success, the API's message otherwise. */
export async function writeAdminNote(targetType: NoteTargetType, targetId: string, body: string): Promise<string | null> {
  return send(`${env.apiBaseUrl}/admin/notes`, "POST", { targetType, targetId, body }, "La note n'a pas pu être enregistrée.");
}

export async function editAdminNote(noteId: string, body: string): Promise<string | null> {
  return send(`${env.apiBaseUrl}/admin/notes/${noteId}`, "PATCH", { body }, "La note n'a pas pu être modifiée.");
}

export async function followUpAdminNote(noteId: string, body: string): Promise<string | null> {
  return send(`${env.apiBaseUrl}/admin/notes/${noteId}/follow-ups`, "POST", { body }, "Le suivi n'a pas pu être enregistré.");
}

/** Settles an open note, or reopens a settled one, with an optional text. */
export async function turnAdminNote(noteId: string, to: "settle" | "reopen", body: string): Promise<string | null> {
  return send(`${env.apiBaseUrl}/admin/notes/${noteId}/${to}`, "POST", { body }, "L'état de la note n'a pas pu être changé.");
}

async function send(url: string, method: string, payload: object, fallback: string): Promise<string | null> {
  try {
    const res = await apiFetch(url, { method, headers: { "Content-Type": "application/json" }, body: JSON.stringify(payload) });
    if (res.ok) return null;
    return errorMessage(await res.json().catch(() => null), fallback);
  } catch {
    return "Impossible de contacter l'API.";
  }
}

function errorMessage(payload: unknown, fallback: string): string {
  if (isObject(payload) && "error" in payload && isObject(payload.error) && hasStringProp(payload.error, "message")) {
    return payload.error.message;
  }
  return fallback;
}

/** Story 46.3: what a list row needs to show a target's open notes without opening it. */
export type OpenNoteSummary = { count: number; latest: { body: string; author: NoteAuthor; createdAt: string } };

export function isOpenNoteSummary(v: unknown): v is OpenNoteSummary {
  return (
    isObject(v) &&
    "count" in v &&
    typeof v.count === "number" &&
    "latest" in v &&
    isObject(v.latest) &&
    hasStringProp(v.latest, "body") &&
    "author" in v.latest &&
    isNoteAuthor(v.latest.author) &&
    hasStringProp(v.latest, "createdAt")
  );
}

/**
 * Story 46.3: the open notes of a whole list of targets, in one call, keyed by target id. A target without an open
 * note is absent. null when the read failed.
 */
export async function fetchOpenNoteSummaries(targetType: NoteTargetType, targetIds: string[]): Promise<Record<string, OpenNoteSummary> | null> {
  if (targetIds.length === 0) return {};
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/notes/open-summaries`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ targetType, targetIds }),
    });
    if (!res.ok) return null;
    const data = dataOf(await res.json());
    if (!isObject(data) || Array.isArray(data)) return null;
    return Object.fromEntries(Object.entries(data).filter((entry): entry is [string, OpenNoteSummary] => isOpenNoteSummary(entry[1])));
  } catch {
    return null;
  }
}
