"use client";

import { Check, Copy, Loader2, Play, RefreshCw, Tv } from "lucide-react";
import { useEffect, useState } from "react";

import { env } from "@/lib/env";
import type { OverlaySlot } from "./overlay-api";
import { fetchOverlaySlots, testOverlayEvent } from "./overlay-api";

type Props = { sessionId: string };

type WidgetSpec = { key: WidgetKey; label: string; tab: string; dims: string; hint: string };
type WidgetKey = "notifications" | "goals" | "log" | "reachable";

const WIDGETS: readonly WidgetSpec[] = [
  { key: "notifications", label: "Notifications d'items", tab: "Notifications", dims: "440 × 160", hint: "Toasts d'objets reçus" },
  { key: "goals", label: "Objectif atteint", tab: "Objectif", dims: "1920 × 1080", hint: "Célébration plein écran" },
  { key: "log", label: "Historique / log", tab: "Log", dims: "460 × 640", hint: "Flux d'événements" },
  { key: "reachable", label: "Checks réalisables", tab: "Checks", dims: "360 × 480", hint: "Liste des checks faisables maintenant (choisis un joueur via le scope)" },
];

// Event types the operator can simulate, each mapped to the overlay (tab) that renders it.
const TEST_TYPES: readonly { value: string; label: string; widget: WidgetKey }[] = [
  { value: "item-received", label: "Objet reçu", widget: "notifications" },
  { value: "goal", label: "Objectif atteint", widget: "goals" },
  { value: "location-checked", label: "Location validée", widget: "log" },
  { value: "hint", label: "Indice", widget: "log" },
  { value: "chat", label: "Chat", widget: "log" },
];

// Checkerboard so a transparent overlay is visible in the preview.
const CHECKER_STYLE = {
  width: 320,
  height: 180,
  backgroundColor: "#1a1a22",
  backgroundImage:
    "linear-gradient(45deg, #24242e 25%, transparent 25%), linear-gradient(-45deg, #24242e 25%, transparent 25%), linear-gradient(45deg, transparent 75%, #24242e 75%), linear-gradient(-45deg, transparent 75%, #24242e 75%)",
  backgroundSize: "16px 16px",
  backgroundPosition: "0 0, 0 8px, 8px -8px, -8px 0",
} as const;

export function OverlayLinksPanel({ sessionId }: Props) {
  const [error, setError] = useState<string | null>(null);
  const [copied, setCopied] = useState<string | null>(null);
  const [testing, setTesting] = useState<string | null>(null);
  const [previewNonce, setPreviewNonce] = useState(0);
  const [slots, setSlots] = useState<OverlaySlot[]>([]);
  // Selected scope: "" = all players, "group" = custom group, else a slot key.
  const [scope, setScope] = useState<string>("");
  // Slot keys ticked when scope === "group" (?slot=a,b).
  const [group, setGroup] = useState<string[]>([]);
  const [activeTab, setActiveTab] = useState<WidgetKey>("notifications");
  // Test form: which event type to simulate, and for which player.
  const [testType, setTestType] = useState<string>("item-received");
  const [testPlayer, setTestPlayer] = useState<string>("");

  useEffect(() => {
    let cancelled = false;
    void fetchOverlaySlots(sessionId).then((list) => {
      if (!cancelled && list) setSlots(list);
    });
    return () => {
      cancelled = true;
    };
  }, [sessionId]);

  // Permanent, tokenless overlay URL. slotKey "" = all players; a slot key (or comma list) scopes it.
  function overlayUrl(widget: string, slotKey: string): string {
    const params = new URLSearchParams();
    if (slotKey) params.set("slot", slotKey);
    const qs = params.toString();
    return `${env.appUrl}/o/${sessionId}/${widget}${qs ? `?${qs}` : ""}`;
  }

  async function runTest(): Promise<void> {
    const t = TEST_TYPES.find((x) => x.value === testType) ?? TEST_TYPES[0];
    const player = testPlayer || slots[0]?.key || "";
    const switchingTab = activeTab !== t.widget;
    setActiveTab(t.widget); // show the overlay that renders this event type
    setTesting(t.value);
    setError(null);
    // Switching tab remounts the preview iframe (it reconnects its stream); wait for that before
    // publishing, else the preview misses the one-shot event (Mercure doesn't replay). A real OBS
    // source stays connected, so this delay only matters for the in-panel preview.
    if (switchingTab) {
      await new Promise((resolve) => setTimeout(resolve, 1_500));
    }
    const ok = await testOverlayEvent(sessionId, t.value, player);
    setTesting(null);
    if (!ok) {
      setError("Test impossible - Mercure indisponible (ou session non démarrée).");
    }
  }

  async function copy(url: string, key: string): Promise<void> {
    try {
      await navigator.clipboard.writeText(url);
      setCopied(key);
      setTimeout(() => {
        setCopied((cur) => (cur === key ? null : cur));
      }, 1_500);
    } catch {
      setError("Copie impossible - copie l'URL manuellement.");
    }
  }

  const activeWidget = WIDGETS.find((w) => w.key === activeTab) ?? WIDGETS[0];
  // Selected scope drives the single URL shown: "" = all, "group" = the ticked players, else one slot.
  const effectiveSlot = scope === "group" ? group.join(",") : scope;
  const overlayLink = overlayUrl(activeWidget.key, effectiveSlot);

  return (
    <section className="grid gap-4 rounded-lg border border-border bg-surface p-4">
      <header className="flex items-center gap-2">
        <Tv aria-hidden="true" className="size-4 text-accent-text" />
        <h2 className="font-heading text-sm font-semibold text-foreground">Overlays OBS</h2>
      </header>

      <p className="text-xs text-muted-foreground">
        Ajoute ces URLs comme sources « Navigateur » dans OBS. Lecture seule, fond transparent. Liens
        permanents : colle-les une fois, ils ne changent pas.
      </p>

      {error ? (
        <p className="rounded border border-danger/40 bg-danger/10 px-3 py-2 text-xs text-danger">{error}</p>
      ) : null}

      <div className="grid gap-4">
          {/* Scope: a single select (all / custom group / one slot) drives the link below. */}
          <div className="flex flex-wrap items-center gap-2">
            <label className="text-xs font-semibold text-foreground" htmlFor="overlay-scope">
              Afficher
            </label>
            <select
              className="h-8 rounded border border-border bg-surface px-2 text-xs text-foreground focus:border-accent focus:outline-none"
              id="overlay-scope"
              onChange={(e) => {
                setScope(e.target.value);
              }}
              value={scope}
            >
              <option value="">Tous les joueurs</option>
              <option value="group">Groupe personnalisé</option>
              {slots.map((s) => (
                <option key={s.key} value={s.key}>
                  {s.name}
                </option>
              ))}
            </select>
            {slots.length === 0 ? (
              <span className="text-[11px] text-muted-foreground">
                (démarre la session pour lister les joueurs)
              </span>
            ) : null}
          </div>

          {scope === "group" ? (
            <GroupPicker
              group={group}
              onToggle={(key, on) => {
                setGroup((prev) => (on ? [...prev, key] : prev.filter((k) => k !== key)));
              }}
              slots={slots}
            />
          ) : null}

          {/* One widget at a time keeps the panel readable; switch with the tabs. */}
          <div className="flex gap-1 border-b border-border" role="tablist">
            {WIDGETS.map((w) => {
              const active = w.key === activeTab;
              return (
                <button
                  aria-selected={active}
                  className={`-mb-px border-b-2 px-3 py-1.5 text-xs font-semibold transition-colors ${
                    active
                      ? "border-accent text-foreground"
                      : "border-transparent text-muted-foreground hover:text-foreground"
                  }`}
                  key={w.key}
                  onClick={() => {
                    setActiveTab(w.key);
                  }}
                  role="tab"
                  type="button"
                >
                  {w.tab}
                </button>
              );
            })}
          </div>

          <WidgetCard
            copied={copied}
            copyKey={`${activeWidget.key}:${effectiveSlot || "all"}`}
            onCopy={(url, key) => void copy(url, key)}
            onReload={() => {
              setPreviewNonce((n) => n + 1);
            }}
            previewNonce={previewNonce}
            url={overlayLink}
            widget={activeWidget}
          />

          {/* Simulate one event type for one player; the matching overlay tab is shown so the preview
              reacts. The event is overlay-only (never reaches the players' progression pages). */}
          <div className="grid gap-2 rounded border border-border bg-surface-2 p-3">
            <span className="text-xs font-semibold text-foreground">Simuler un événement (test)</span>
            <div className="flex flex-wrap items-end gap-2">
              <label className="grid gap-1 text-[11px] text-muted-foreground">
                Type
                <select
                  className="h-8 rounded border border-border bg-surface px-2 text-xs text-foreground focus:border-accent focus:outline-none"
                  onChange={(e) => {
                    setTestType(e.target.value);
                  }}
                  value={testType}
                >
                  {TEST_TYPES.map((t) => (
                    <option key={t.value} value={t.value}>
                      {t.label}
                    </option>
                  ))}
                </select>
              </label>
              <label className="grid gap-1 text-[11px] text-muted-foreground">
                Joueur
                <select
                  className="h-8 rounded border border-border bg-surface px-2 text-xs text-foreground focus:border-accent focus:outline-none disabled:opacity-50"
                  disabled={slots.length === 0}
                  onChange={(e) => {
                    setTestPlayer(e.target.value);
                  }}
                  value={testPlayer || slots[0]?.key || ""}
                >
                  {slots.map((s) => (
                    <option key={s.key} value={s.key}>
                      {s.name}
                    </option>
                  ))}
                </select>
              </label>
              <button
                className="inline-flex h-8 w-fit items-center gap-1.5 rounded border border-accent/40 bg-accent/10 px-3 text-xs font-semibold text-accent-text transition-colors hover:bg-accent/20 disabled:opacity-50"
                disabled={testing !== null || slots.length === 0}
                onClick={() => void runTest()}
                type="button"
              >
                {testing !== null ? (
                  <Loader2 aria-hidden="true" className="size-3.5 animate-spin" />
                ) : (
                  <Play aria-hidden="true" className="size-3.5" />
                )}
                Envoyer
              </button>
            </div>
            <p className="text-[11px] text-muted-foreground">
              {slots.length === 0
                ? "Démarre la session pour simuler un événement."
                : "Événement visible uniquement par les overlays. L'aperçu ne l'affiche que si le filtre « Afficher » inclut ce joueur."}
            </p>
          </div>
      </div>
    </section>
  );
}

function GroupPicker({
  slots,
  group,
  onToggle,
}: {
  slots: OverlaySlot[];
  group: string[];
  onToggle: (key: string, on: boolean) => void;
}) {
  if (slots.length === 0) return null;
  return (
    <fieldset className="flex flex-wrap items-center gap-x-3 gap-y-1.5 rounded border border-border bg-surface-2 px-3 py-2">
      <legend className="px-1 text-xs font-semibold text-foreground">Groupe personnalisé</legend>
      {slots.map((s) => (
        <label className="flex items-center gap-1.5 text-xs text-foreground" key={s.key}>
          <input
            checked={group.includes(s.key)}
            onChange={(e) => {
              onToggle(s.key, e.target.checked);
            }}
            type="checkbox"
          />
          {s.name}
        </label>
      ))}
      <span className="text-[11px] text-muted-foreground">
        {group.length > 0
          ? `${group.length} joueur(s) dans le lien`
          : "coche les joueurs à regrouper"}
      </span>
    </fieldset>
  );
}

function WidgetCard({
  widget,
  url,
  copyKey,
  previewNonce,
  copied,
  onCopy,
  onReload,
}: {
  widget: WidgetSpec;
  url: string;
  copyKey: string;
  previewNonce: number;
  copied: string | null;
  onCopy: (url: string, key: string) => void;
  onReload: () => void;
}) {
  return (
    <div className="flex min-w-0 flex-col gap-3 rounded border border-border bg-surface-2 p-3 lg:flex-row lg:items-start">
      {/* Live preview of the selected scope. Rendered at a true 1280×720 viewport and CSS-downscaled
          into the 320×180 box, so it matches what OBS shows at 720p - not a tiny clipped viewport. */}
      <div className="relative max-w-full shrink-0 overflow-hidden rounded border border-border" style={CHECKER_STYLE}>
        <iframe
          className="absolute left-0 top-0 origin-top-left"
          key={`${copyKey}-${previewNonce}`}
          src={url}
          style={{ width: 1280, height: 720, transform: "scale(0.25)", border: 0 }}
          title={`Aperçu ${widget.label}`}
        />
      </div>

      <div className="flex min-w-0 flex-1 flex-col gap-2">
        <div className="flex items-baseline justify-between gap-2">
          <p className="text-sm font-semibold text-foreground">{widget.label}</p>
          <span className="font-mono text-[10px] text-muted-foreground">{widget.dims}</span>
        </div>
        <p className="text-xs text-muted-foreground">{widget.hint}</p>

        <div className="flex items-center gap-2">
          <input
            className="h-8 min-w-0 flex-1 rounded border border-border bg-surface px-2 font-mono text-xs text-muted-foreground focus:border-accent focus:outline-none"
            onFocus={(e) => {
              e.currentTarget.select();
            }}
            readOnly
            value={url}
          />
          <button
            aria-label="Copier l'URL"
            className="inline-flex size-8 shrink-0 items-center justify-center rounded border border-border bg-surface text-muted-foreground transition-colors hover:border-accent hover:text-foreground"
            onClick={() => {
              onCopy(url, copyKey);
            }}
            type="button"
          >
            {copied === copyKey ? (
              <Check aria-hidden="true" className="size-3.5 text-success" />
            ) : (
              <Copy aria-hidden="true" className="size-3.5" />
            )}
          </button>
        </div>

        <div className="flex flex-wrap items-center gap-2">
          <button
            className="inline-flex w-fit items-center gap-1.5 text-xs font-semibold text-accent-text hover:underline"
            onClick={onReload}
            type="button"
          >
            <RefreshCw aria-hidden="true" className="size-3.5" />
            Recharger l&apos;aperçu
          </button>
        </div>
      </div>
    </div>
  );
}
