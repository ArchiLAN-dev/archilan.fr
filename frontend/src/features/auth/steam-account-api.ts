import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";

export async function saveSteamAccount(
  steamProfile: string,
): Promise<{ ok: boolean; invalid: boolean }> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/account/steam`, {
      method: "PUT",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ steamProfile }),
    });

    if (res.ok) return { ok: true, invalid: false };
    if (res.status === 422) return { ok: false, invalid: true };
    return { ok: false, invalid: false };
  } catch {
    return { ok: false, invalid: false };
  }
}

export async function removeSteamAccount(): Promise<boolean> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/account/steam`, { method: "DELETE" });
    return res.ok;
  } catch {
    return false;
  }
}
