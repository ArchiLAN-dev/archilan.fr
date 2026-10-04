"use client";

import { useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { Pause, Pencil, Play, Trash2 } from "lucide-react";

import { buttonVariants } from "@/components/ui/button";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { Dialog, DialogBody, DialogFooter } from "@/components/ui/dialog";
import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import { PelleAmount } from "./pelle-amount";
import {
  deleteShopItem,
  editShopItem,
  fetchAdminShop,
  listShopItem,
  setShopItemPaused,
  type AdminShop,
  type AdminShopItem,
  type AdminShopStatus,
  type CosmeticType,
  type NewShopItem,
  type ShopItemTerms,
} from "./shop-api";
import { COSMETIC_TYPE_LABELS, ShopCosmeticPreview, useCosmeticLabel } from "./shop-cosmetics";

export const STATUS_LABELS: Record<AdminShopStatus, string> = { on_sale: "En vente", upcoming: "À venir", ended: "Terminé", paused: "En pause" };
const STATUS_TONES: Record<AdminShopStatus, string> = {
  on_sale: "bg-success/15 text-success",
  upcoming: "bg-accent/15 text-accent-text",
  ended: "bg-surface-2 text-muted-foreground",
  paused: "bg-warning/15 text-warning",
};
const FILTERS: readonly (AdminShopStatus | "all")[] = ["all", "on_sale", "upcoming", "paused", "ended"];
const dateFormatter = new Intl.DateTimeFormat("fr-FR", { dateStyle: "medium", timeStyle: "short", timeZone: "Europe/Paris" });

/** A `datetime-local` value (Paris) to the API's ISO date, or null when empty. */
export function isoOrNull(local: string): string | null {
  if (local === "") return null;
  const date = new Date(local);
  return Number.isNaN(date.getTime()) ? null : date.toISOString().replace(/\.\d{3}Z$/, "+00:00");
}

/** The API's ISO date to a `datetime-local` value in the browser's time, or "" when none. */
export function localOrEmpty(iso: string | null): string {
  if (iso === null) return "";
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return "";
  const pad = (n: number) => String(n).padStart(2, "0");
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

type Handlers = {
  onList: (item: NewShopItem) => Promise<string | null>;
  onEdit: (itemId: string, terms: ShopItemTerms) => Promise<string | null>;
  onPause: (itemId: string, paused: boolean) => Promise<string | null>;
  onDelete: (itemId: string) => Promise<string | null>;
};

/**
 * The admin side of the shop (stories 41.7 and 41.12): what sells, putting a shop cosmetic on sale (for good or for
 * a season) with its preview, and each item's price, window, pause and deletion.
 */
export function AdminShopPage() {
  const queryClient = useQueryClient();
  const { data, isLoading } = useQuery({ queryKey: ["admin-shop"], queryFn: fetchAdminShop, staleTime: DEFAULT_STALE_TIME, retry: false });

  async function after(error: string | null): Promise<string | null> {
    await queryClient.invalidateQueries({ queryKey: ["admin-shop"] });
    await queryClient.invalidateQueries({ queryKey: ["shop"] });
    return error;
  }

  return (
    <section className="grid gap-6 p-6 md:p-8">
      <header>
        <h1 className="font-heading text-2xl font-bold text-foreground">Boutique</h1>
        <p className="mt-1 text-sm text-muted-foreground">Cadres et bannières vendus contre des pelles en or, dans l&apos;onglet Cosmétiques de /boutique.</p>
      </header>
      {isLoading ? <p className="text-sm text-muted-foreground">Chargement…</p> : null}
      {!isLoading && !data ? <p className="text-sm text-danger">Impossible de charger la boutique.</p> : null}
      {data ? (
        <AdminShopView
          onDelete={async (itemId) => after(await deleteShopItem(itemId))}
          onEdit={async (itemId, terms) => after(await editShopItem(itemId, terms))}
          onList={async (item) => after(await listShopItem(item))}
          onPause={async (itemId, paused) => after(await setShopItemPaused(itemId, paused))}
          shop={data}
        />
      ) : null}
    </section>
  );
}

export function AdminShopView({ shop, ...handlers }: { shop: AdminShop } & Handlers) {
  const label = useCosmeticLabel();
  const [filter, setFilter] = useState<AdminShopStatus | "all">("all");
  const [message, setMessage] = useState<{ tone: "ok" | "error"; text: string } | null>(null);
  const [editing, setEditing] = useState<AdminShopItem | null>(null);
  const [deleting, setDeleting] = useState<AdminShopItem | null>(null);
  const [pending, setPending] = useState(false);

  const onSale = shop.items.filter((item) => item.status === "on_sale").length;
  const sales = shop.items.reduce((sum, item) => sum + item.sales, 0);
  const pelles = shop.items.reduce((sum, item) => sum + item.pelles, 0);
  const shown = filter === "all" ? shop.items : shop.items.filter((item) => item.status === filter);

  function report(error: string | null, ok: string): void {
    setMessage(error === null ? { tone: "ok", text: ok } : { tone: "error", text: error });
  }

  async function confirmDelete(): Promise<void> {
    if (deleting === null) return;
    setPending(true);
    report(await handlers.onDelete(deleting.id), "Article supprimé.");
    setPending(false);
    setDeleting(null);
  }

  return (
    <div className="grid gap-6">
      <dl className="grid gap-3 sm:grid-cols-3">
        <Figure label="Articles en vente" value={String(onSale)} />
        <Figure label="Ventes" value={String(sales)} />
        <Figure label="Pelles encaissées" value={<PelleAmount amount={pelles} className="text-warning" />} />
      </dl>

      <ListForm onList={handlers.onList} report={report} sellable={shop.sellable} />

      {message !== null ? (
        <p className={`text-sm ${message.tone === "ok" ? "text-success" : "text-danger"}`} role="status">
          {message.text}
        </p>
      ) : null}

      {shop.items.length === 0 ? (
        <p className="text-sm text-muted-foreground">Rien n&apos;a encore été mis en vente.</p>
      ) : (
        <div className="grid gap-3">
          <div aria-label="Filtrer par état" className="flex flex-wrap gap-2" role="group">
            {FILTERS.map((value) => (
              <button
                aria-pressed={filter === value}
                className={`rounded-full border px-3 py-1 text-xs font-semibold ${filter === value ? "border-accent bg-accent/15 text-foreground" : "border-border text-muted-foreground hover:text-foreground"}`}
                key={value}
                onClick={() => setFilter(value)}
                type="button"
              >
                {value === "all" ? `Tous (${shop.items.length})` : `${STATUS_LABELS[value]} (${shop.items.filter((item) => item.status === value).length})`}
              </button>
            ))}
          </div>
          <ul className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            {shown.map((item) => (
              <li className="grid overflow-hidden rounded-xl border border-border bg-surface" key={item.id}>
                <div className="relative">
                  <ShopCosmeticPreview className="h-28" cosmeticKey={item.cosmeticKey} type={item.type} />
                  <span className={`absolute left-3 top-3 rounded-full px-2 py-0.5 text-xs font-semibold ${STATUS_TONES[item.status]}`}>{STATUS_LABELS[item.status]}</span>
                </div>
                <div className="grid gap-2 p-4 text-sm">
                  <div className="flex items-start justify-between gap-2">
                    <div className="min-w-0">
                      <p className="truncate font-semibold text-foreground">{label(item.type, item.cosmeticKey)}</p>
                      <p className="text-xs text-muted-foreground">{COSMETIC_TYPE_LABELS[item.type]}</p>
                    </div>
                    <PelleAmount amount={item.price} className="shrink-0 font-semibold" />
                  </div>
                  <p className="text-xs text-muted-foreground">
                    {item.availableFrom !== null ? `Dès le ${dateFormatter.format(new Date(item.availableFrom))}` : "Dès sa mise en vente"}
                    {item.availableUntil !== null ? ` · jusqu'au ${dateFormatter.format(new Date(item.availableUntil))}` : " · sans fin"}
                  </p>
                  <p className="text-xs text-muted-foreground">
                    {item.sales === 0 ? "Aucune vente" : `${item.sales} ${item.sales > 1 ? "ventes" : "vente"} · `}
                    {item.sales > 0 ? <PelleAmount amount={item.pelles} /> : null}
                  </p>
                  <div className="flex flex-wrap gap-1 pt-1">
                    <button className={buttonVariants({ variant: "ghost" })} onClick={() => setEditing(item)} type="button">
                      <Pencil aria-hidden className="size-4" />
                      Modifier
                    </button>
                    <button
                      className={buttonVariants({ variant: "ghost" })}
                      onClick={async () => {
                        const paused = item.status !== "paused";
                        report(await handlers.onPause(item.id, paused), paused ? "Vente en pause." : "Vente reprise.");
                      }}
                      type="button"
                    >
                      {item.status === "paused" ? <Play aria-hidden className="size-4" /> : <Pause aria-hidden className="size-4" />}
                      {item.status === "paused" ? "Reprendre" : "Mettre en pause"}
                    </button>
                    <button className={`${buttonVariants({ variant: "ghost" })} hover:text-danger`} onClick={() => setDeleting(item)} type="button">
                      <Trash2 aria-hidden className="size-4" />
                      Supprimer
                    </button>
                  </div>
                </div>
              </li>
            ))}
          </ul>
        </div>
      )}

      {editing !== null ? (
        <EditDialog
          item={editing}
          label={label(editing.type, editing.cosmeticKey)}
          onClose={() => setEditing(null)}
          onSave={async (terms) => {
            const error = await handlers.onEdit(editing.id, terms);
            report(error, "Article modifié.");
            if (error === null) setEditing(null);
          }}
        />
      ) : null}

      <ConfirmDialog
        confirmLabel="Supprimer"
        description={
          deleting !== null && deleting.sales > 0
            ? `L'article disparaît de la boutique et de la base. Ses ${deleting.sales} acheteurs gardent le cosmétique et leur historique de pelles.`
            : "L'article disparaît de la boutique et de la base."
        }
        onConfirm={() => void confirmDelete()}
        onOpenChange={(open) => {
          if (!open && !pending) setDeleting(null);
        }}
        open={deleting !== null}
        pending={pending}
        title={deleting !== null ? `Supprimer « ${label(deleting.type, deleting.cosmeticKey)} » ?` : ""}
        tone="danger"
      />
    </div>
  );
}

function Figure({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div className="rounded-xl border border-border p-4">
      <dt className="text-xs text-muted-foreground">{label}</dt>
      <dd className="mt-1 font-heading text-2xl font-bold text-foreground">{value}</dd>
    </div>
  );
}

const fieldClass = "min-h-9 rounded-lg border border-border bg-background px-3 text-sm text-foreground";

function ListForm({
  sellable,
  onList,
  report,
}: {
  sellable: AdminShop["sellable"];
  onList: Handlers["onList"];
  report: (error: string | null, ok: string) => void;
}) {
  const label = useCosmeticLabel();
  const [type, setType] = useState<CosmeticType>("frame");
  const [cosmeticKey, setCosmeticKey] = useState("");
  const [price, setPrice] = useState("");
  const [from, setFrom] = useState("");
  const [until, setUntil] = useState("");

  const parsedPrice = Number.parseInt(price, 10);
  const canList = cosmeticKey !== "" && Number.isInteger(parsedPrice) && parsedPrice >= 1 && parsedPrice <= 10000;

  if (sellable.frame.length === 0 && sellable.banner.length === 0) {
    return (
      <p className="rounded-lg border border-dashed border-border px-4 py-6 text-sm text-muted-foreground">
        Aucun cosmétique à vendre pour l&apos;instant. Ajoute un cadre ou une bannière avec l&apos;accès « Boutique » dans les pages
        Cadres ou Bannières : il pourra ensuite être mis en vente ici.
      </p>
    );
  }

  return (
    <form
      className="grid gap-4 rounded-xl border border-border p-4 lg:grid-cols-[1fr_16rem]"
      onSubmit={async (event) => {
        event.preventDefault();
        const error = await onList({ type, cosmeticKey, price: parsedPrice, availableFrom: isoOrNull(from), availableUntil: isoOrNull(until) });
        report(error, "Mis en vente.");
        if (error === null) {
          setCosmeticKey("");
          setPrice("");
        }
      }}
    >
      <div className="grid gap-3 sm:grid-cols-2">
        <h2 className="font-heading text-lg font-semibold text-foreground sm:col-span-2">Mettre en vente</h2>
        <label className="grid gap-1 text-sm">
          <span className="font-medium text-foreground">Type</span>
          <select
            className={fieldClass}
            onChange={(e) => {
              setType(e.target.value === "banner" ? "banner" : "frame");
              setCosmeticKey("");
            }}
            value={type}
          >
            <option value="frame">Cadre</option>
            <option value="banner">Bannière</option>
          </select>
        </label>
        <label className="grid gap-1 text-sm">
          <span className="font-medium text-foreground">Cosmétique</span>
          <select className={fieldClass} onChange={(e) => setCosmeticKey(e.target.value)} value={cosmeticKey}>
            <option value="">Choisir…</option>
            {sellable[type].map((key) => (
              <option key={key} value={key}>
                {label(type, key)}
              </option>
            ))}
          </select>
        </label>
        <label className="grid gap-1 text-sm">
          <span className="font-medium text-foreground">Prix (pelles)</span>
          <input className={fieldClass} inputMode="numeric" max={10000} min={1} onChange={(e) => setPrice(e.target.value)} type="number" value={price} />
        </label>
        <span className="hidden sm:block" />
        <label className="grid gap-1 text-sm">
          <span className="font-medium text-foreground">Début (facultatif)</span>
          <input className={fieldClass} onChange={(e) => setFrom(e.target.value)} type="datetime-local" value={from} />
        </label>
        <label className="grid gap-1 text-sm">
          <span className="font-medium text-foreground">Fin (facultatif)</span>
          <input className={fieldClass} onChange={(e) => setUntil(e.target.value)} type="datetime-local" value={until} />
        </label>
        <div className="sm:col-span-2">
          <button className={buttonVariants({ variant: "primary" })} disabled={!canList} type="submit">
            Mettre en vente
          </button>
        </div>
      </div>
      <div className="grid content-start gap-2">
        <p className="text-xs font-medium text-muted-foreground">Aperçu</p>
        <div className="overflow-hidden rounded-xl border border-border">
          {cosmeticKey !== "" ? (
            <ShopCosmeticPreview className="h-32" cosmeticKey={cosmeticKey} type={type} />
          ) : (
            <p className="grid h-32 place-items-center text-xs text-muted-foreground">Choisis un cosmétique</p>
          )}
        </div>
      </div>
    </form>
  );
}

function EditDialog({ item, label, onClose, onSave }: { item: AdminShopItem; label: string; onClose: () => void; onSave: (terms: ShopItemTerms) => Promise<void> }) {
  const [price, setPrice] = useState(String(item.price));
  const [from, setFrom] = useState(localOrEmpty(item.availableFrom));
  const [until, setUntil] = useState(localOrEmpty(item.availableUntil));
  const [pending, setPending] = useState(false);
  const parsedPrice = Number.parseInt(price, 10);
  const valid = Number.isInteger(parsedPrice) && parsedPrice >= 1 && parsedPrice <= 10000;

  return (
    <Dialog
      description="Le nouveau prix vaut pour les prochains achats. Laisse une date vide pour ne pas borner la vente."
      onOpenChange={(open) => {
        if (!open) onClose();
      }}
      open
      title={`Modifier « ${label} »`}
    >
      <form
        className="flex min-h-0 flex-1 flex-col"
        onSubmit={async (event) => {
          event.preventDefault();
          setPending(true);
          await onSave({ price: parsedPrice, availableFrom: isoOrNull(from), availableUntil: isoOrNull(until) });
          setPending(false);
        }}
      >
        <DialogBody className="grid gap-3">
          <label className="grid gap-1 text-sm">
            <span className="font-medium text-foreground">Prix (pelles)</span>
            <input className={fieldClass} inputMode="numeric" max={10000} min={1} onChange={(e) => setPrice(e.target.value)} type="number" value={price} />
          </label>
          <label className="grid gap-1 text-sm">
            <span className="font-medium text-foreground">Début</span>
            <input className={fieldClass} onChange={(e) => setFrom(e.target.value)} type="datetime-local" value={from} />
          </label>
          <label className="grid gap-1 text-sm">
            <span className="font-medium text-foreground">Fin</span>
            <input className={fieldClass} onChange={(e) => setUntil(e.target.value)} type="datetime-local" value={until} />
          </label>
        </DialogBody>
        <DialogFooter>
          <button className={buttonVariants({ variant: "ghost" })} onClick={onClose} type="button">
            Annuler
          </button>
          <button className={buttonVariants({ variant: "primary" })} disabled={!valid || pending} type="submit">
            Enregistrer
          </button>
        </DialogFooter>
      </form>
    </Dialog>
  );
}
