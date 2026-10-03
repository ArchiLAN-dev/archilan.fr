"use client";

import { useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";

import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import { PelleAmount } from "./pelle-amount";
import { cosmeticLabel, fetchAdminShop, listShopItem, retireShopItem, type AdminShop, type NewShopItem } from "./shop-api";

const STATUS_LABELS: Record<string, string> = { on_sale: "En vente", upcoming: "À venir", ended: "Terminé", retired: "Retiré" };
const dateFormatter = new Intl.DateTimeFormat("fr-FR", { dateStyle: "medium", timeStyle: "short", timeZone: "Europe/Paris" });

/** A `datetime-local` value (Paris) to the API's ISO date, or null when empty. */
export function isoOrNull(local: string): string | null {
  if (local === "") return null;
  const date = new Date(local);
  return Number.isNaN(date.getTime()) ? null : date.toISOString().replace(/\.\d{3}Z$/, "+00:00");
}

/**
 * The admin side of the shop (story 41.7): the items ever listed, putting a shop cosmetic on sale (for good or for
 * a season) and retiring one. Only the cosmetics the code catalog marks as shop ones can be sold.
 */
export function AdminShopPage() {
  const queryClient = useQueryClient();
  const { data, isLoading } = useQuery({ queryKey: ["admin-shop"], queryFn: fetchAdminShop, staleTime: DEFAULT_STALE_TIME, retry: false });

  async function refresh(): Promise<void> {
    await queryClient.invalidateQueries({ queryKey: ["admin-shop"] });
  }

  return (
    <section className="grid gap-6 p-6 md:p-8">
      <header>
        <h1 className="font-heading text-2xl font-bold text-foreground">Boutique</h1>
        <p className="mt-1 text-sm text-muted-foreground">Cadres et bannières vendus contre des pelles en or.</p>
      </header>
      {isLoading ? <p className="text-sm text-muted-foreground">Chargement…</p> : null}
      {!isLoading && !data ? <p className="text-sm text-danger">Impossible de charger la boutique.</p> : null}
      {data ? (
        <AdminShopView
          onList={async (item) => {
            const error = await listShopItem(item);
            await refresh();
            return error;
          }}
          onRetire={async (itemId) => {
            const error = await retireShopItem(itemId);
            await refresh();
            return error;
          }}
          shop={data}
        />
      ) : null}
    </section>
  );
}

export function AdminShopView({
  shop,
  onList,
  onRetire,
}: {
  shop: AdminShop;
  onList: (item: NewShopItem) => Promise<string | null>;
  onRetire: (itemId: string) => Promise<string | null>;
}) {
  const [type, setType] = useState<"frame" | "banner">("frame");
  const [cosmeticKey, setCosmeticKey] = useState("");
  const [price, setPrice] = useState("");
  const [from, setFrom] = useState("");
  const [until, setUntil] = useState("");
  const [message, setMessage] = useState<{ tone: "ok" | "error"; text: string } | null>(null);

  const sellable = shop.sellable[type];
  const nothingToSell = shop.sellable.frame.length === 0 && shop.sellable.banner.length === 0;
  const parsedPrice = Number.parseInt(price, 10);
  const canList = cosmeticKey !== "" && Number.isInteger(parsedPrice) && parsedPrice >= 1 && parsedPrice <= 10000;
  const fieldClass = "min-h-9 rounded-lg border border-border bg-background px-3 text-sm text-foreground";

  async function list(): Promise<void> {
    const error = await onList({ type, cosmeticKey, price: parsedPrice, availableFrom: isoOrNull(from), availableUntil: isoOrNull(until) });
    setMessage(error === null ? { tone: "ok", text: "Mis en vente." } : { tone: "error", text: error });
    if (error === null) {
      setCosmeticKey("");
      setPrice("");
    }
  }

  async function retire(itemId: string): Promise<void> {
    if (!window.confirm("Retirer cet article de la boutique ? Ceux qui l'ont acheté le gardent.")) return;
    const error = await onRetire(itemId);
    setMessage(error === null ? { tone: "ok", text: "Retiré de la boutique." } : { tone: "error", text: error });
  }

  return (
    <div className="grid gap-6">
      {nothingToSell ? (
        <p className="rounded-lg border border-dashed border-border px-4 py-6 text-sm text-muted-foreground">
          Aucun cosmétique de boutique dans le catalogue pour l&apos;instant. Un cadre ou une bannière dessiné par un membre
          doit d&apos;abord être ajouté au code (marqué « boutique ») ; il pourra ensuite être mis en vente ici.
        </p>
      ) : (
        <form
          className="grid gap-3 rounded-xl border border-border p-4 sm:grid-cols-2 lg:grid-cols-5"
          onSubmit={(event) => {
            event.preventDefault();
            void list();
          }}
        >
          <label className="grid gap-1 text-sm">
            <span className="font-medium text-foreground">Type</span>
            <select className={fieldClass} onChange={(e) => { setType(e.target.value === "banner" ? "banner" : "frame"); setCosmeticKey(""); }} value={type}>
              <option value="frame">Cadre</option>
              <option value="banner">Bannière</option>
            </select>
          </label>
          <label className="grid gap-1 text-sm">
            <span className="font-medium text-foreground">Cosmétique</span>
            <select className={fieldClass} onChange={(e) => setCosmeticKey(e.target.value)} value={cosmeticKey}>
              <option value="">Choisir…</option>
              {sellable.map((key) => (
                <option key={key} value={key}>
                  {cosmeticLabel(type, key)}
                </option>
              ))}
            </select>
          </label>
          <label className="grid gap-1 text-sm">
            <span className="font-medium text-foreground">Prix (pelles)</span>
            <input className={fieldClass} inputMode="numeric" max={10000} min={1} onChange={(e) => setPrice(e.target.value)} type="number" value={price} />
          </label>
          <label className="grid gap-1 text-sm">
            <span className="font-medium text-foreground">Début (facultatif)</span>
            <input className={fieldClass} onChange={(e) => setFrom(e.target.value)} type="datetime-local" value={from} />
          </label>
          <label className="grid gap-1 text-sm">
            <span className="font-medium text-foreground">Fin (facultatif)</span>
            <input className={fieldClass} onChange={(e) => setUntil(e.target.value)} type="datetime-local" value={until} />
          </label>
          <div className="sm:col-span-2 lg:col-span-5">
            <button className="inline-flex min-h-9 items-center rounded-lg border border-border px-3 text-sm font-semibold hover:border-accent disabled:opacity-40" disabled={!canList} type="submit">
              Mettre en vente
            </button>
          </div>
        </form>
      )}

      {message !== null ? <p className={`text-sm ${message.tone === "ok" ? "text-success" : "text-danger"}`}>{message.text}</p> : null}

      {shop.items.length === 0 ? (
        <p className="text-sm text-muted-foreground">Rien n&apos;a encore été mis en vente.</p>
      ) : (
        <ul className="divide-y divide-border rounded-xl border border-border">
          {shop.items.map((item) => (
            <li className="flex flex-wrap items-center justify-between gap-3 px-4 py-3 text-sm" key={item.id}>
              <span className="min-w-0">
                <span className="font-semibold text-foreground">{cosmeticLabel(item.type, item.cosmeticKey)}</span>
                <span className="text-xs text-muted-foreground">
                  {" · "}
                  {STATUS_LABELS[item.status]}
                  {item.availableFrom !== null ? ` · dès le ${dateFormatter.format(new Date(item.availableFrom))}` : ""}
                  {item.availableUntil !== null ? ` · jusqu'au ${dateFormatter.format(new Date(item.availableUntil))}` : ""}
                </span>
              </span>
              <span className="flex items-center gap-3">
                <PelleAmount amount={item.price} className="text-xs font-semibold" />
                {item.status !== "retired" ? (
                  <button className="text-xs text-muted-foreground hover:text-danger" onClick={() => void retire(item.id)} type="button">
                    Retirer
                  </button>
                ) : null}
              </span>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
