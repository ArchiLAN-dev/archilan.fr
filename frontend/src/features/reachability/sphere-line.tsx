"use client";

import { CheckCircle2, Route, Search } from "lucide-react";
import { useEffect, useRef, useState } from "react";

import type { CheckEntry, SphereEntry } from "./types";

function SphereDetail({
  sphere,
  currentSlot,
  filter,
  onFilter,
}: {
  sphere: SphereEntry;
  currentSlot: number;
  filter: string;
  onFilter: (v: string) => void;
}) {
  const q = filter.trim().toLowerCase();
  const statusOrder: Record<NonNullable<CheckEntry["check_status"]>, number> = {
    reachable: 0, blocked: 1, checked: 2,
  };
  const sorted = [...sphere.locations].sort((a, b) => {
    const sd = (statusOrder[a.check_status ?? "blocked"] ?? 1) - (statusOrder[b.check_status ?? "blocked"] ?? 1);
    return sd !== 0 ? sd : a.name.localeCompare(b.name);
  });
  const filtered = q
    ? sorted.filter((l) => l.name.toLowerCase().includes(q) || (l.item?.name.toLowerCase().includes(q) ?? false))
    : sorted;

  const statusLabel =
    sphere.status === "past" ? "Complétée" :
    sphere.status === "current" ? "En cours" :
    sphere.status === "future" ? "À venir" :
    "Bloquée (BK)";

  return (
    <div className="grid gap-3 border-t border-border pt-3">
      <div className="flex flex-wrap items-center gap-3">
        <div>
          <span className="font-heading text-sm font-semibold text-foreground">
            {sphere.index === -1 ? "Inaccessibles" : `Sphère ${sphere.index}`}
          </span>
          <span className="ml-2 text-xs text-muted-foreground">- {statusLabel}</span>
        </div>
        <div className="flex gap-3 text-xs">
          {sphere.counts.checked > 0 ? <span className="text-success">{sphere.counts.checked} faits</span> : null}
          {sphere.counts.reachable > 0 ? <span className="text-accent-warm">{sphere.counts.reachable} accessibles</span> : null}
          {sphere.counts.blocked > 0 ? <span className="text-muted-foreground">{sphere.counts.blocked} en attente</span> : null}
        </div>
        <div className="relative ml-auto">
          <Search aria-hidden="true" className="pointer-events-none absolute left-2 top-1/2 size-3 -translate-y-1/2 text-muted-foreground" />
          <input
            className="h-7 w-40 rounded border border-border bg-surface-2 pl-6 pr-2 text-xs text-foreground placeholder:text-muted-foreground focus:border-accent-text/50 focus:outline-none"
            onChange={(e) => { onFilter(e.target.value); }}
            placeholder="Filtrer…"
            type="search"
            value={filter}
          />
        </div>
      </div>
      {filtered.length === 0 ? (
        <p className="text-sm text-muted-foreground">Aucun résultat.</p>
      ) : (
        <ul className="max-h-80 divide-y divide-border overflow-y-auto rounded border border-border">
          {filtered.map((loc) => {
            const isOwnItem = loc.item?.slot === currentSlot;
            return (
              <li
                className={`grid gap-0.5 px-3 py-2 ${loc.check_status === "checked" ? "opacity-50" : ""}`}
                key={loc.id}
              >
                <div className="flex items-center gap-2">
                  {loc.check_status === "checked" ? (
                    <CheckCircle2 aria-hidden="true" className="size-3 shrink-0 text-success/60" />
                  ) : loc.check_status === "reachable" ? (
                    <span aria-hidden="true" className="size-2 shrink-0 rounded-full bg-accent-warm/70" />
                  ) : (
                    <span aria-hidden="true" className="size-2 shrink-0 rounded-full bg-border" />
                  )}
                  <span className={`text-sm ${
                    loc.check_status === "checked" ? "text-muted-foreground/60" :
                    loc.check_status === "reachable" ? "text-foreground" :
                    "text-muted-foreground"
                  }`}>
                    {loc.name}
                  </span>
                </div>
                {loc.item ? (
                  <span className="ml-4 flex flex-wrap items-baseline gap-1.5 text-xs text-muted-foreground/60">
                    <span>→ {loc.item.name}</span>
                    {!isOwnItem ? (
                      <span className="rounded bg-surface-2 px-1.5 py-0.5 font-mono text-[10px] text-accent-text">
                        {loc.item.slot_name}
                      </span>
                    ) : null}
                  </span>
                ) : null}
              </li>
            );
          })}
        </ul>
      )}
    </div>
  );
}

export function SphereLine({
  spheres,
  currentSlot,
}: {
  spheres: SphereEntry[];
  currentSlot: number;
}) {
  const defaultIdx = spheres.findIndex((s) => s.status === "current");
  const [selectedIdx, setSelectedIdx] = useState(defaultIdx >= 0 ? defaultIdx : 0);
  const [sphereFilter, setSphereFilter] = useState("");
  const currentRef = useRef<HTMLButtonElement>(null);

  useEffect(() => {
    currentRef.current?.scrollIntoView({ behavior: "smooth", block: "nearest", inline: "center" });
  }, []);

  const selected = spheres[selectedIdx];

  return (
    <div className="grid gap-4 rounded border border-border bg-surface p-4">
      <div className="flex items-center gap-2">
        <Route aria-hidden="true" className="size-4 text-accent-text" />
        <h2 className="font-heading text-sm font-semibold text-foreground">
          Sphères de progression
          <span className="ml-2 font-mono text-xs font-normal text-muted-foreground">
            ({spheres.filter((s) => s.index >= 0).length})
          </span>
        </h2>
      </div>
      <div className="flex gap-2 overflow-x-auto pb-1">
        {spheres.map((sphere, i) => {
          const isSelected = i === selectedIdx;
          const label = sphere.index === -1 ? "BK" : `S${sphere.index}`;
          const base = "shrink-0 flex flex-col items-center gap-0.5 rounded border px-3 py-2 text-xs font-semibold transition-colors cursor-pointer";
          const cls =
            sphere.status === "past"
              ? isSelected ? `${base} border-success bg-success/20 text-success` : `${base} border-success/30 bg-success/5 text-success/70 hover:border-success/60`
              : sphere.status === "current"
              ? isSelected ? `${base} border-accent-warm bg-accent-warm/20 text-accent-warm` : `${base} border-accent-warm/50 bg-accent-warm/10 text-accent-warm hover:border-accent-warm`
              : sphere.status === "blocked"
              ? isSelected ? `${base} border-danger bg-danger/20 text-danger` : `${base} border-danger/30 bg-danger/5 text-danger/70 hover:border-danger/50`
              : isSelected ? `${base} border-accent-text/50 bg-accent/20 text-foreground` : `${base} border-border bg-surface text-muted-foreground hover:border-accent-text/30`;

          return (
            <button
              className={cls}
              key={sphere.index}
              onClick={() => { setSelectedIdx(i); setSphereFilter(""); }}
              ref={sphere.status === "current" ? currentRef : undefined}
              type="button"
            >
              <span className="font-mono">{label}</span>
              {sphere.status === "past" ? (
                <span className="text-[10px]">{sphere.counts.total}✓</span>
              ) : sphere.status === "current" ? (
                <span className="flex items-center gap-1 text-[10px]">
                  <span aria-hidden="true" className="size-1.5 animate-pulse rounded-full bg-accent-warm" />
                  {sphere.counts.reachable}ac
                </span>
              ) : (
                <span className="text-[10px]">{sphere.counts.total}</span>
              )}
            </button>
          );
        })}
      </div>
      {selected ? (
        <SphereDetail
          currentSlot={currentSlot}
          filter={sphereFilter}
          onFilter={setSphereFilter}
          sphere={selected}
        />
      ) : null}
    </div>
  );
}
