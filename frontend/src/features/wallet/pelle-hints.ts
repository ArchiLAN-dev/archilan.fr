"use client";

import { useQuery, useQueryClient } from "@tanstack/react-query";

import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import { hasBooleanProp, hasNullableNumberProp, hasNumberProp } from "@/lib/type-guards";
import type { PelleHintOffer } from "@/features/reachability/check-row";

/** Story 41.3: what a slot page may buy in pelles. */
export type PelleHintTerms = {
  enabled: boolean;
  itemPrice: number;
  locationPrice: number;
  /** The event's pelles, on an event session only. */
  eventBalance: number | null;
  goldBalance: number;
};

export type PelleHintTarget = { kind: "item"; itemName: string } | { kind: "location"; locationId: number };

export function isPelleHintTerms(v: unknown): v is PelleHintTerms {
  return (
    typeof v === "object" &&
    v !== null &&
    hasBooleanProp(v, "enabled") &&
    hasNumberProp(v, "itemPrice") &&
    hasNumberProp(v, "locationPrice") &&
    hasNullableNumberProp(v, "eventBalance") &&
    hasNumberProp(v, "goldBalance")
  );
}

export async function fetchPelleHintTerms(sessionId: string, slotIndex: string): Promise<PelleHintTerms | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/sessions/${sessionId}/slots/${slotIndex}/pelle-hints`);
    if (!res.ok) return null;
    const payload: unknown = await res.json();
    return isPelleHintTerms(payload) ? payload : null;
  } catch {
    return null;
  }
}

/** Throws when the purchase fails, so the hint button shows the failure; a failed hint is refunded server side. */
export async function buyPelleHint(sessionId: string, slotIndex: string, target: PelleHintTarget, requestId: string): Promise<void> {
  const res = await apiFetch(`${env.apiBaseUrl}/sessions/${sessionId}/slots/${slotIndex}/pelle-hints`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ ...target, requestId }),
  });
  if (!res.ok) throw new Error(`pelle hint failed: ${res.status}`);
}

/** Can the member pay this price, with the event's pelles or with gold pelles? */
export function canAfford(terms: PelleHintTerms, price: number): boolean {
  return (terms.eventBalance ?? 0) >= price || terms.goldBalance >= price;
}

/**
 * The pelles option of the hint buttons of a slot page: undefined when the session does not sell hints for
 * pelles. Each purchase carries its own request id, minted when the player confirms.
 */
export function usePelleHintOffers(sessionId: string | null, slotIndex: string): {
  item?: PelleHintOffer<string>;
  location?: PelleHintOffer<number>;
} {
  const queryClient = useQueryClient();
  const { data } = useQuery({
    queryKey: ["pelle-hint-terms", sessionId, slotIndex],
    queryFn: () => (sessionId === null ? Promise.resolve(null) : fetchPelleHintTerms(sessionId, slotIndex)),
    enabled: sessionId !== null,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
  });

  if (sessionId === null || !data?.enabled) return {};

  async function buy(target: PelleHintTarget): Promise<void> {
    if (sessionId === null) return;
    try {
      await buyPelleHint(sessionId, slotIndex, target, crypto.randomUUID());
    } finally {
      await queryClient.invalidateQueries({ queryKey: ["pelle-hint-terms", sessionId, slotIndex] });
      await queryClient.invalidateQueries({ queryKey: ["my-wallet"] });
    }
  }

  return {
    item: { price: data.itemPrice, affordable: canAfford(data, data.itemPrice), onBuy: (itemName) => buy({ kind: "item", itemName }) },
    location: {
      price: data.locationPrice,
      affordable: canAfford(data, data.locationPrice),
      onBuy: (locationId) => buy({ kind: "location", locationId }),
    },
  };
}
