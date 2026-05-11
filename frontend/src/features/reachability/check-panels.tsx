"use client";

import { CheckCircle2, MapPin, PackageX, Search, X, XCircle } from "lucide-react";
import { useEffect, useRef, useState } from "react";

import { CheckRow, HintButton } from "./check-row";
import type { CheckEntry, ItemEntry, ItemLocation } from "./types";

function ItemLocationsModal({
  itemName,
  locations,
  onClose,
}: {
  itemName: string;
  locations: ItemLocation[];
  onClose: () => void;
}) {
  const dialogRef = useRef<HTMLDialogElement>(null);
  const [filter, setFilter] = useState("");

  useEffect(() => {
    dialogRef.current?.showModal();
  }, []);

  const q = filter.trim().toLowerCase();
  const sorted = locations.slice().sort((a, b) => a.locationName.localeCompare(b.locationName));
  const filtered = q ? sorted.filter((l) => l.locationName.toLowerCase().includes(q)) : sorted;

  return (
    <dialog
      className="m-auto max-h-[80vh] w-full max-w-md overflow-hidden rounded border border-border bg-surface p-0 shadow-xl backdrop:bg-black/60"
      onClose={onClose}
      ref={dialogRef}
    >
      <div className="flex items-center justify-between border-b border-border px-4 py-3">
        <div className="flex items-center gap-2">
          <MapPin aria-hidden="true" className="size-4 shrink-0 text-muted-foreground" />
          <h2 className="font-heading text-sm font-semibold text-foreground">{itemName}</h2>
          <span className="font-mono text-xs text-muted-foreground">({q ? `${filtered.length}/` : ""}{locations.length})</span>
        </div>
        <button
          aria-label="Fermer"
          className="inline-flex items-center rounded border border-border p-1 text-muted-foreground hover:border-accent hover:text-foreground"
          onClick={onClose}
          type="button"
        >
          <X className="size-3.5" />
        </button>
      </div>
      <div className="border-b border-border px-4 py-2">
        <div className="relative">
          <Search aria-hidden="true" className="pointer-events-none absolute left-2 top-1/2 size-3 -translate-y-1/2 text-muted-foreground" />
          <input
            autoFocus
            className="h-7 w-full rounded border border-border bg-surface-2 pl-6 pr-2 text-xs text-foreground placeholder:text-muted-foreground focus:border-accent-text/50 focus:outline-none"
            onChange={(e) => { setFilter(e.target.value); }}
            placeholder="Filtrer…"
            type="search"
            value={filter}
          />
        </div>
      </div>
      {filtered.length === 0 ? (
        <p className="px-4 py-6 text-sm text-muted-foreground">Aucun résultat pour « {filter} ».</p>
      ) : (
        <ul className="max-h-[calc(80vh-7rem)] divide-y divide-border overflow-y-auto">
          {filtered.map((loc, i) => (
            <li className="flex items-center gap-2.5 px-4 py-2.5" key={i}>
              <MapPin aria-hidden="true" className={`size-3 shrink-0 ${
                loc.checkStatus === "reachable" ? "text-success" :
                loc.checkStatus === "checked"   ? "text-muted-foreground/40" :
                "text-muted-foreground/50"
              }`} />
              <span className={`min-w-0 flex-1 text-sm ${
                loc.checkStatus === "checked"
                  ? "text-muted-foreground/40 line-through"
                  : loc.checkStatus === "reachable"
                  ? "text-success/90"
                  : "text-muted-foreground"
              }`}>
                {loc.locationName}
              </span>
              {loc.gameName ? (
                <span className="shrink-0 rounded bg-surface-2 px-1.5 py-0.5 font-mono text-[10px] text-accent-text">
                  {loc.gameName}
                </span>
              ) : null}
            </li>
          ))}
        </ul>
      )}
    </dialog>
  );
}

export function ItemListPanel({
  title,
  items,
  variant,
  emptyMessage,
  onHintRequest,
  hintFree = false,
  hintCost = 0,
  itemLocations,
}: {
  title: string;
  items: ItemEntry[];
  variant: "received" | "not-received";
  emptyMessage: string;
  onHintRequest?: (itemName: string) => Promise<void>;
  hintFree?: boolean;
  hintCost?: number;
  itemLocations?: Record<number, ItemLocation[]>;
}) {
  const [filter, setFilter] = useState("");
  const [modalItem, setModalItem] = useState<{ id: number; name: string } | null>(null);
  const q = filter.trim().toLowerCase();
  const filtered = (q ? items.filter((i) => i.name.toLowerCase().includes(q)) : items)
    .slice().sort((a, b) => a.name.localeCompare(b.name));

  const modalLocs = modalItem ? (itemLocations?.[modalItem.id] ?? []) : [];

  return (
    <>
      {modalItem ? (
        <ItemLocationsModal
          itemName={modalItem.name}
          locations={modalLocs}
          onClose={() => { setModalItem(null); }}
        />
      ) : null}
      <div className="rounded border border-border bg-surface">
        <div className="flex flex-wrap items-center gap-2 border-b border-border px-4 py-3">
          {variant === "not-received" ? (
            <PackageX aria-hidden="true" className="size-4 shrink-0 text-muted-foreground" />
          ) : null}
          <h2 className="font-heading text-sm font-semibold text-foreground">
            {title}
            <span className="ml-2 font-mono text-xs font-normal text-muted-foreground">
              ({q ? `${filtered.length}/` : ""}{items.length})
            </span>
          </h2>
          <div className="relative ml-auto">
            <Search aria-hidden="true" className="pointer-events-none absolute left-2 top-1/2 size-3 -translate-y-1/2 text-muted-foreground" />
            <input
              className="h-7 w-36 rounded border border-border bg-surface-2 pl-6 pr-2 text-xs text-foreground placeholder:text-muted-foreground focus:border-accent-text/50 focus:outline-none"
              onChange={(e) => { setFilter(e.target.value); }}
              placeholder="Filtrer…"
              type="search"
              value={filter}
            />
          </div>
        </div>
        {items.length === 0 ? (
          <p className="px-4 py-6 text-sm text-muted-foreground">{emptyMessage}</p>
        ) : filtered.length === 0 ? (
          <p className="px-4 py-6 text-sm text-muted-foreground">Aucun résultat pour « {filter} ».</p>
        ) : (
          <ul className="max-h-72 divide-y divide-border overflow-y-auto">
            {filtered.map((item) => {
              const locs = itemLocations?.[item.id];
              return (
                <li className="flex items-center justify-between gap-3 px-4 py-2.5" key={item.id}>
                  <span className={`text-sm ${variant === "received" ? "text-foreground" : "text-muted-foreground"}`}>{item.name}</span>
                  <div className="flex shrink-0 items-center gap-1.5">
                    {locs && locs.length > 0 ? (
                      <button
                        aria-label="Voir les locations"
                        className="inline-flex items-center rounded border border-border p-1 text-muted-foreground transition-colors hover:border-accent-text/40 hover:text-foreground"
                        onClick={() => { setModalItem({ id: item.id, name: item.name }); }}
                        type="button"
                      >
                        <MapPin className="size-3" />
                      </button>
                    ) : null}
                    {variant === "not-received" && onHintRequest ? (
                      <HintButton free={hintFree} hintCost={hintCost} onHint={() => onHintRequest(item.name)} />
                    ) : null}
                    <span className={`rounded border px-1.5 py-0.5 font-mono text-xs font-semibold ${
                      variant === "received"
                        ? "border-accent/30 bg-accent/10 text-accent-text"
                        : "border-border text-muted-foreground"
                    }`}>
                      ×{item.count}
                    </span>
                  </div>
                </li>
              );
            })}
          </ul>
        )}
      </div>
    </>
  );
}

export function CheckListPanel({
  title,
  checks,
  currentSlot,
  variant,
  emptyMessage,
  onHintRequest,
  hintFree = false,
  hintCost = 0,
}: {
  title: string;
  checks: CheckEntry[];
  currentSlot: number;
  variant: "reachable" | "unreachable";
  emptyMessage: string;
  onHintRequest?: (locationId: number) => Promise<void>;
  hintFree?: boolean;
  hintCost?: number;
}) {
  const [filter, setFilter] = useState("");
  const q = filter.trim().toLowerCase();
  const filtered = (q
    ? checks.filter((c) => c.name.toLowerCase().includes(q) || (c.item?.name.toLowerCase().includes(q) ?? false))
    : checks).slice().sort((a, b) => a.name.localeCompare(b.name));

  const isReachable = variant === "reachable";

  return (
    <div className={`rounded border bg-surface ${isReachable ? "border-success/30" : "border-border"}`}>
      <div className={`flex flex-wrap items-center gap-2 border-b px-4 py-3 ${isReachable ? "border-success/20" : "border-border"}`}>
        {isReachable ? (
          <CheckCircle2 aria-hidden="true" className="size-4 shrink-0 text-success" />
        ) : (
          <XCircle aria-hidden="true" className="size-4 shrink-0 text-muted-foreground" />
        )}
        <h2 className="font-heading text-sm font-semibold text-foreground">
          {title}
          <span className="ml-2 font-mono text-xs font-normal text-muted-foreground">
            ({q ? `${filtered.length}/` : ""}{checks.length})
          </span>
        </h2>
        <div className="relative ml-auto">
          <Search aria-hidden="true" className="pointer-events-none absolute left-2 top-1/2 size-3 -translate-y-1/2 text-muted-foreground" />
          <input
            className={`h-7 w-40 rounded border border-border bg-surface-2 pl-6 pr-2 text-xs text-foreground placeholder:text-muted-foreground focus:outline-none ${isReachable ? "focus:border-success/50" : "focus:border-accent-text/50"}`}
            onChange={(e) => { setFilter(e.target.value); }}
            placeholder="Filtrer…"
            type="search"
            value={filter}
          />
        </div>
      </div>
      {checks.length === 0 ? (
        <p className="px-4 py-6 text-sm text-muted-foreground">{emptyMessage}</p>
      ) : filtered.length === 0 ? (
        <p className="px-4 py-6 text-sm text-muted-foreground">Aucun résultat pour « {filter} ».</p>
      ) : (
        <ul className="max-h-96 divide-y divide-border overflow-y-auto">
          {filtered.map((check) => (
            <CheckRow check={check} currentSlot={currentSlot} hintCost={hintCost} hintFree={hintFree} key={check.id} onHintRequest={onHintRequest} variant={variant} />
          ))}
        </ul>
      )}
    </div>
  );
}
