"use client";

import { useRef, useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { Loader2, Shovel, X } from "lucide-react";

import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import { hasBooleanProp, hasNumberProp, hasStringProp } from "@/lib/type-guards";
import { PelleAmount, pellesLabel } from "./pelle-amount";

/** Story 41.4: an open bounty of the session, as a player sees it. */
export type ItemBounty = { id: string; slotName: string; itemName: string; amount: number; reward: number; mine: boolean };

export type ItemBounties = { enabled: boolean; bounties: ItemBounty[] };

export const BOUNTY_MIN = 10;
export const BOUNTY_MAX = 1000;

function isBounty(v: unknown): v is ItemBounty {
  return (
    typeof v === "object" &&
    v !== null &&
    hasStringProp(v, "id") &&
    hasStringProp(v, "slotName") &&
    hasStringProp(v, "itemName") &&
    hasNumberProp(v, "amount") &&
    hasNumberProp(v, "reward") &&
    hasBooleanProp(v, "mine")
  );
}

export function isItemBounties(v: unknown): v is ItemBounties {
  return typeof v === "object" && v !== null && hasBooleanProp(v, "enabled") && "bounties" in v && Array.isArray(v.bounties) && v.bounties.every(isBounty);
}

export async function fetchItemBounties(sessionId: string, slotIndex: string): Promise<ItemBounties | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/sessions/${sessionId}/slots/${slotIndex}/bounties`);
    if (!res.ok) return null;
    const payload: unknown = await res.json();
    return isItemBounties(payload) ? payload : null;
  } catch {
    return null;
  }
}

/** Null on success, otherwise the server's message to show. */
export async function postItemBounty(sessionId: string, slotIndex: string, itemName: string, amount: number, requestId: string): Promise<string | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/sessions/${sessionId}/slots/${slotIndex}/bounties`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ itemName, amount, requestId }),
    });
    if (res.status === 201) return null;
    return errorMessage(await res.json().catch(() => null)) ?? "La prime n'a pas pu être posée.";
  } catch {
    return "Impossible de contacter l'API.";
  }
}

export async function withdrawItemBounty(sessionId: string, bountyId: string): Promise<string | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/sessions/${sessionId}/bounties/${bountyId}`, { method: "DELETE" });
    if (res.status === 204) return null;
    return errorMessage(await res.json().catch(() => null)) ?? "La prime n'a pas pu être retirée.";
  } catch {
    return "Impossible de contacter l'API.";
  }
}

function errorMessage(payload: unknown): string | null {
  if (typeof payload === "object" && payload !== null && "error" in payload && typeof payload.error === "object" && payload.error !== null && hasStringProp(payload.error, "message")) {
    return payload.error.message;
  }
  return null;
}

/**
 * « Primes de la partie » (story 41.4): the open bounties of the session - what to send, to whom, for how much -
 * and, for the player of this slot, a bounty to post on an item they still miss. Hidden when the party has none.
 */
export function ItemBountiesPanel({ sessionId, slotIndex, missingItems }: { sessionId: string; slotIndex: string; missingItems: string[] }) {
  const queryClient = useQueryClient();
  const { data } = useQuery({
    queryKey: ["item-bounties", sessionId, slotIndex],
    queryFn: () => fetchItemBounties(sessionId, slotIndex),
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
  });

  if (!data?.enabled) return null;

  async function refresh(): Promise<void> {
    await queryClient.invalidateQueries({ queryKey: ["item-bounties", sessionId, slotIndex] });
    await queryClient.invalidateQueries({ queryKey: ["my-wallet"] });
  }

  return (
    <ItemBountiesView
      bounties={data.bounties}
      missingItems={missingItems}
      onPost={async (itemName, amount, requestId) => {
        const error = await postItemBounty(sessionId, slotIndex, itemName, amount, requestId);
        await refresh();
        return error;
      }}
      onWithdraw={async (bountyId) => {
        const error = await withdrawItemBounty(sessionId, bountyId);
        await refresh();
        return error;
      }}
    />
  );
}

export function ItemBountiesView({
  bounties,
  missingItems,
  onPost,
  onWithdraw,
}: {
  bounties: ItemBounty[];
  missingItems: string[];
  onPost: (itemName: string, amount: number, requestId: string) => Promise<string | null>;
  onWithdraw: (bountyId: string) => Promise<string | null>;
}) {
  const [itemName, setItemName] = useState("");
  const [amount, setAmount] = useState("");
  const [pending, setPending] = useState(false);
  const [message, setMessage] = useState<{ tone: "ok" | "error"; text: string } | null>(null);
  const [confirming, setConfirming] = useState<{ kind: "post" } | { kind: "withdraw"; bountyId: string; itemName: string } | null>(null);
  // One id per bounty, kept across a retry so a double submit holds the pelles once.
  const requestIdRef = useRef<string | null>(null);

  const parsedAmount = Number.parseInt(amount, 10);
  const canPost = !pending && itemName !== "" && Number.isInteger(parsedAmount) && parsedAmount >= BOUNTY_MIN && parsedAmount <= BOUNTY_MAX;

  async function post(): Promise<void> {
    requestIdRef.current ??= crypto.randomUUID();
    setPending(true);
    setMessage(null);
    const error = await onPost(itemName, parsedAmount, requestIdRef.current);
    if (error === null) {
      requestIdRef.current = null;
      setItemName("");
      setAmount("");
      setMessage({ tone: "ok", text: "Prime posée : tes pelles sont mises de côté." });
    } else {
      setMessage({ tone: "error", text: error });
    }
    setPending(false);
  }

  async function withdraw(bountyId: string): Promise<void> {
    setPending(true);
    setMessage(null);
    const error = await onWithdraw(bountyId);
    setMessage(error === null ? { tone: "ok", text: "Prime retirée." } : { tone: "error", text: error });
    setPending(false);
  }

  async function confirm(): Promise<void> {
    if (confirming?.kind === "post") await post();
    if (confirming?.kind === "withdraw") await withdraw(confirming.bountyId);
    setConfirming(null);
  }

  const fieldClass = "min-h-8 rounded border border-border bg-surface-2 px-2 text-xs text-foreground";

  return (
    <section aria-labelledby="item-bounties-title" className="grid gap-3 rounded border border-border bg-surface p-4">
      <h2 className="flex items-center gap-2 font-heading text-sm font-semibold text-foreground" id="item-bounties-title">
        <Shovel aria-hidden className="size-4 text-accent-text" />
        Primes de la partie
      </h2>
      {bounties.length === 0 ? (
        <p className="text-xs text-muted-foreground">Aucune prime en cours.</p>
      ) : (
        <ul className="divide-y divide-border">
          {bounties.map((bounty) => (
            <li className="flex items-center justify-between gap-3 py-2 text-sm" key={bounty.id}>
              <span className="min-w-0">
                <span className="text-foreground">{bounty.itemName}</span>
                <span className="text-xs text-muted-foreground"> pour {bounty.slotName}</span>
              </span>
              <span className="flex shrink-0 items-center gap-2">
                <PelleAmount amount={bounty.reward} className="text-xs font-semibold text-warning" />
                {bounty.mine ? (
                  <button
                    aria-label={`Retirer la prime sur ${bounty.itemName}`}
                    className="inline-flex items-center rounded border border-border px-1.5 py-0.5 text-xs text-muted-foreground hover:border-danger/40 hover:text-danger"
                    onClick={() => setConfirming({ kind: "withdraw", bountyId: bounty.id, itemName: bounty.itemName })}
                    type="button"
                  >
                    <X aria-hidden className="size-3" />
                  </button>
                ) : null}
              </span>
            </li>
          ))}
        </ul>
      )}
      {missingItems.length > 0 ? (
        <form
          className="flex flex-wrap items-end gap-2"
          onSubmit={(event) => {
            event.preventDefault();
            if (canPost) setConfirming({ kind: "post" });
          }}
        >
          <label className="grid gap-1 text-xs">
            <span className="text-muted-foreground">Objet qui te manque</span>
            <select className={fieldClass} onChange={(e) => setItemName(e.target.value)} value={itemName}>
              <option value="">Choisir…</option>
              {missingItems.map((name) => (
                <option key={name} value={name}>
                  {name}
                </option>
              ))}
            </select>
          </label>
          <label className="grid gap-1 text-xs">
            <span className="text-muted-foreground">
              Pelles ({BOUNTY_MIN} à {BOUNTY_MAX})
            </span>
            <input className={`${fieldClass} w-24`} inputMode="numeric" max={BOUNTY_MAX} min={BOUNTY_MIN} onChange={(e) => setAmount(e.target.value)} type="number" value={amount} />
          </label>
          <button
            className="inline-flex min-h-8 items-center gap-1.5 rounded border border-border px-2.5 text-xs font-semibold text-foreground hover:border-accent disabled:cursor-not-allowed disabled:opacity-40"
            disabled={!canPost}
            type="submit"
          >
            {pending ? <Loader2 aria-hidden className="size-3 animate-spin" /> : <Shovel aria-hidden className="size-3" />}
            Poser une prime
          </button>
        </form>
      ) : null}
      {message !== null ? <p className={`text-xs ${message.tone === "ok" ? "text-success" : "text-danger"}`}>{message.text}</p> : null}

      <ConfirmDialog
        confirmLabel={confirming?.kind === "withdraw" ? "Retirer" : "Poser la prime"}
        description={
          confirming?.kind === "withdraw"
            ? "Tes pelles te sont rendues en entier."
            : `Tu offres ${pellesLabel(parsedAmount)} à qui t'enverra ${itemName}. Elles sont mises de côté dès maintenant ; 10 % sont prélevés au versement.`
        }
        onConfirm={() => void confirm()}
        onOpenChange={(open) => {
          if (!open && !pending) setConfirming(null);
        }}
        open={confirming !== null}
        pending={pending}
        title={confirming?.kind === "withdraw" ? `Retirer la prime sur ${confirming.itemName} ?` : "Poser une prime ?"}
      />
    </section>
  );
}
