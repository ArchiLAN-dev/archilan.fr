import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { hasNullableStringProp, hasNumberProp, hasStringProp } from "@/lib/type-guards";
import type { Profile } from "./account-profile";

type ConfirmEmailError = { error: { code: string } };

function hasProp<K extends string>(obj: object, key: K): obj is Record<K, unknown> {
  return key in obj;
}

export function isConfirmEmailError(v: unknown): v is ConfirmEmailError {
  if (typeof v !== "object" || v === null || !hasProp(v, "error")) return false;
  const { error } = v;
  if (typeof error !== "object" || error === null || !hasProp(error, "code")) return false;
  return typeof error.code === "string";
}

export async function resendEmailConfirmation(): Promise<boolean> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/auth/resend-confirmation`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: "{}",
    });
    return res.ok;
  } catch {
    return false;
  }
}

// ── Account profile ───────────────────────────────────────────────────────────

function isProfile(v: unknown): v is Profile {
  if (typeof v !== "object" || v === null) return false;
  if (!hasStringProp(v, "id") || !hasStringProp(v, "email") || !hasStringProp(v, "displayName")) return false;
  if (!hasNullableStringProp(v, "discordUsername") || !hasNullableStringProp(v, "steamProfile")) return false;
  if (!("roles" in v) || !Array.isArray(v.roles) || !v.roles.every((r): r is string => typeof r === "string")) return false;
  if (!hasNullableStringProp(v, "emailVerifiedAt")) return false;
  return hasStringProp(v, "createdAt") && hasStringProp(v, "updatedAt");
}

export async function fetchAccountProfile(): Promise<Profile | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/account/profile`);
    if (!res.ok) return null;
    const json: unknown = await res.json();
    if (typeof json !== "object" || json === null || !("data" in json)) return null;
    return isProfile(json.data) ? json.data : null;
  } catch {
    return null;
  }
}

// ── Profile slug (custom /joueurs/{slug} URL) ─────────────────────────────────

export type AccountSlugState = {
  slug: string | null;
  /** ISO date until which the slug cannot be changed, or null when editable. Resolved at fetch time. */
  cooldownUntil: string | null;
};

export async function fetchAccountSlugState(): Promise<AccountSlugState | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/account/profile`);
    if (!res.ok) return null;
    const json: unknown = await res.json();
    if (typeof json !== "object" || json === null || !("data" in json)) return null;
    const data: unknown = json.data;
    if (typeof data !== "object" || data === null) return null;
    const slug = hasNullableStringProp(data, "slug") ? data.slug : null;
    const next = hasNullableStringProp(data, "nextSlugChangeAllowedAt") ? data.nextSlugChangeAllowedAt : null;
    // Only keep the date if the cooldown is still in the future - computed here, at fetch time,
    // so Date.now() never runs during a component render (AC-HK3).
    const cooldownUntil = next !== null && new Date(next).getTime() > Date.now() ? next : null;
    return { slug, cooldownUntil };
  } catch {
    return null;
  }
}

// ── Account registrations ─────────────────────────────────────────────────────

export type RegistrationStatus = "pending" | "confirmed" | "cancelled";

export type SessionStatus =
  | "draft" | "validating" | "ready"
  | "generating" | "generated" | "launching"
  | "running" | "idle" | "restarting"
  | "stopped" | "failed" | "crashed" | "finished";

export type Registration = {
  registrationId: string;
  eventSlug: string;
  eventTitle: string;
  eventStartDate: string | null;
  registrationStatus: RegistrationStatus;
  slotCount: number;
  sessionStatus: SessionStatus | null;
};

const SESSION_STATUSES: readonly SessionStatus[] = [
  "draft", "validating", "ready",
  "generating", "generated", "launching",
  "running", "idle", "restarting",
  "stopped", "failed", "crashed", "finished",
];

function isRegistrationStatus(v: unknown): v is RegistrationStatus {
  return v === "pending" || v === "confirmed" || v === "cancelled";
}

function isSessionStatus(v: unknown): v is SessionStatus {
  return SESSION_STATUSES.some((s) => s === v);
}

function isRegistration(v: unknown): v is Registration {
  if (typeof v !== "object" || v === null) return false;
  const sessionStatus: unknown = Reflect.get(v, "sessionStatus");
  return (
    hasStringProp(v, "registrationId") &&
    hasStringProp(v, "eventSlug") &&
    hasStringProp(v, "eventTitle") &&
    hasNullableStringProp(v, "eventStartDate") &&
    isRegistrationStatus(Reflect.get(v, "registrationStatus")) &&
    hasNumberProp(v, "slotCount") &&
    (sessionStatus === null || isSessionStatus(sessionStatus))
  );
}

export async function fetchAccountRegistrations(): Promise<Registration[] | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/account/registrations`);
    if (!res.ok) return null;
    const json: unknown = await res.json();
    if (typeof json !== "object" || json === null || !("data" in json)) return null;
    const data: unknown = json.data;
    if (!Array.isArray(data) || !data.every(isRegistration)) return null;
    return data;
  } catch {
    return null;
  }
}
