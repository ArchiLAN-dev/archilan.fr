"use client";

import { CheckCircle2, PackageX, Search, XCircle } from "lucide-react";
import { useState } from "react";

import { CheckRow } from "./check-row";
import type { CheckEntry, ItemEntry } from "./types";

export function ItemListPanel({
  title,
  items,
  variant,
  emptyMessage,
}: {
  title: string;
  items: ItemEntry[];
  variant: "received" | "not-received";
  emptyMessage: string;
}) {
  const [filter, setFilter] = useState("");
  const q = filter.trim().toLowerCase();
  const filtered = (q ? items.filter((i) => i.name.toLowerCase().includes(q)) : items)
    .slice().sort((a, b) => a.name.localeCompare(b.name));

  return (
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
          {filtered.map((item) => (
            <li className="flex items-center justify-between gap-3 px-4 py-2.5" key={item.id}>
              <span className={`text-sm ${variant === "received" ? "text-foreground" : "text-muted-foreground"}`}>{item.name}</span>
              <span className={`shrink-0 rounded border px-1.5 py-0.5 font-mono text-xs font-semibold ${
                variant === "received"
                  ? "border-accent/30 bg-accent/10 text-accent-text"
                  : "border-border text-muted-foreground"
              }`}>
                ×{item.count}
              </span>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

export function CheckListPanel({
  title,
  checks,
  currentSlot,
  variant,
  emptyMessage,
}: {
  title: string;
  checks: CheckEntry[];
  currentSlot: number;
  variant: "reachable" | "unreachable";
  emptyMessage: string;
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
            <CheckRow check={check} currentSlot={currentSlot} key={check.id} variant={variant} />
          ))}
        </ul>
      )}
    </div>
  );
}
