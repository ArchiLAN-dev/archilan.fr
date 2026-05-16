import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";

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
