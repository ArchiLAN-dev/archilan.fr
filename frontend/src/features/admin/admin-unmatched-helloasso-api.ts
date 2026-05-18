import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";

export type UnmatchedHelloAssoOrder = {
  helloassoOrderId: number;
  formSlug: string;
  amountCents: number;
  payerEmail: string | null;
  payerFirstName: string | null;
  payerLastName: string | null;
  paidAt: string | null;
  syncedAt: string | null;
};

export async function fetchUnmatchedHelloAssoOrders(): Promise<UnmatchedHelloAssoOrder[] | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/helloasso-orders/unmatched`);
    if (!res.ok) return null;
    const payload: unknown = await res.json();
    return isUnmatchedOrdersPayload(payload) ? payload.data : null;
  } catch {
    return null;
  }
}

export async function triggerHelloAssoSync(): Promise<boolean> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/helloasso/sync`, {
      method: "POST",
    });
    return res.ok || res.status === 202;
  } catch {
    return false;
  }
}

export async function reconcileHelloAssoOrder(orderId: number, userId: string): Promise<boolean> {
  try {
    const res = await apiFetch(
      `${env.apiBaseUrl}/admin/helloasso-orders/${orderId}/reconcile`,
      {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ userId }),
      },
    );
    return res.ok;
  } catch {
    return false;
  }
}

function isUnmatchedOrder(v: unknown): v is UnmatchedHelloAssoOrder {
  if (typeof v !== "object" || v === null) return false;
  if (!("helloassoOrderId" in v) || typeof v.helloassoOrderId !== "number") return false;
  if (!("formSlug" in v) || typeof v.formSlug !== "string") return false;
  return true;
}

function isUnmatchedOrdersPayload(v: unknown): v is { data: UnmatchedHelloAssoOrder[] } {
  if (typeof v !== "object" || v === null) return false;
  if (!("data" in v) || !Array.isArray(v.data)) return false;
  return v.data.every(isUnmatchedOrder);
}
