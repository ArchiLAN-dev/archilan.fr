"use client";

import { useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";

import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import { PelleAmount } from "./pelle-amount";
import { buyShopItem, cosmeticLabel, fetchShop, type ShopItem } from "./shop-api";

const untilFormatter = new Intl.DateTimeFormat("fr-FR", { day: "numeric", month: "long", timeZone: "Europe/Paris" });

/**
 * « Boutique » (story 41.7): the cosmetics on sale for gold pelles. Empty until members draw some - the free
 * frames and banners stay free.
 */
export function ShopPage() {
  const queryClient = useQueryClient();
  const { data, isLoading } = useQuery({ queryKey: ["shop"], queryFn: fetchShop, staleTime: DEFAULT_STALE_TIME, retry: false });

  if (isLoading) return <p className="text-sm text-muted-foreground">Chargement de la boutique…</p>;
  if (!data) return <p className="text-sm text-danger">Impossible de charger la boutique pour le moment.</p>;

  return (
    <ShopView
      items={data}
      onBuy={async (itemId) => {
        const error = await buyShopItem(itemId);
        await queryClient.invalidateQueries({ queryKey: ["shop"] });
        await queryClient.invalidateQueries({ queryKey: ["my-wallet"] });
        await queryClient.invalidateQueries({ queryKey: ["community-my-profile"] });
        return error;
      }}
    />
  );
}

export function ShopView({ items, onBuy }: { items: ShopItem[]; onBuy: (itemId: string) => Promise<string | null> }) {
  const [message, setMessage] = useState<{ tone: "ok" | "error"; text: string } | null>(null);
  const [pending, setPending] = useState<string | null>(null);

  async function buy(item: ShopItem): Promise<void> {
    if (!window.confirm(`Acheter « ${cosmeticLabel(item.type, item.cosmeticKey)} » pour ${item.price} pelles ?`)) return;
    setPending(item.id);
    setMessage(null);
    const error = await onBuy(item.id);
    setMessage(error === null ? { tone: "ok", text: "C'est à toi : choisis-le dans ton profil." } : { tone: "error", text: error });
    setPending(null);
  }

  return (
    <div className="grid gap-4">
      <p className="text-sm text-muted-foreground">
        Des cadres et des bannières dessinés par des membres, à acheter avec tes pelles en or. Une fois achetés, ils
        sont à toi pour de bon.
      </p>
      {items.length === 0 ? (
        <p className="rounded-lg border border-dashed border-border px-4 py-8 text-center text-sm text-muted-foreground">
          La boutique est vide pour l&apos;instant : les premiers objets arrivent avec les dessins des membres.
        </p>
      ) : (
        <ul className="grid gap-3 sm:grid-cols-2">
          {items.map((item) => (
            <li className="flex items-center justify-between gap-3 rounded-xl border border-border p-4" key={item.id}>
              <div className="min-w-0">
                <p className="font-semibold text-foreground">{cosmeticLabel(item.type, item.cosmeticKey)}</p>
                <p className="text-xs text-muted-foreground">
                  {item.type === "frame" ? "Cadre d'avatar" : "Bannière"}
                  {item.availableUntil !== null ? ` · jusqu'au ${untilFormatter.format(new Date(item.availableUntil))}` : ""}
                </p>
              </div>
              {item.owned ? (
                <span className="shrink-0 text-xs font-semibold text-success">Possédé</span>
              ) : (
                <button
                  className="inline-flex min-h-9 shrink-0 items-center rounded-lg border border-border px-3 text-sm font-semibold hover:border-accent disabled:opacity-40"
                  disabled={pending !== null}
                  onClick={() => void buy(item)}
                  type="button"
                >
                  <PelleAmount amount={item.price} />
                </button>
              )}
            </li>
          ))}
        </ul>
      )}
      {message !== null ? <p className={`text-sm ${message.tone === "ok" ? "text-success" : "text-danger"}`}>{message.text}</p> : null}
    </div>
  );
}
