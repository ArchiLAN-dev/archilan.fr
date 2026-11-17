"use client";

import { AlertTriangle, Loader2, RotateCcw } from "lucide-react";
import { useCallback, useMemo, useState, useSyncExternalStore } from "react";

import { buttonVariants } from "@/components/ui/button";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { SelectField } from "@/components/ui/select-field";
import { PackCredits } from "@/features/trackers/game-tracker-credits";
import { fetchTrackerArchive, type GameTrackerPack } from "@/features/trackers/tracker-packs-api";

import { createTrackerWorker } from "../engine/create-tracker-worker";
import { AccessibilityLevel } from "../engine/levels";
import type { LogLevel } from "../engine/protocol";
import { TrackerClient } from "../engine/tracker-client";
import { TrackerContextProvider, type TrackerScreenContext } from "./tracker-context";
import { TrackerLayout } from "./tracker-layout";
import { parseSettings, readSettingsRaw, subscribeSettings, writeSettings } from "./tracker-storage";
import {
  DEFAULT_SETTINGS,
  indexItemsByCode,
  LEGEND_ORDER,
  LEVEL_STYLES,
  rootLayoutName,
  type TrackerSettings,
} from "./tracker-view";
import {
  useTrackerSession,
  type PackMessage,
  type TrackerSessionDeps,
  type TrackerSessionPhase,
} from "./use-tracker-session";

/**
 * The tracker of a game (story 54.5): the player picks a pack and a variant, ticks items and follows on the maps what
 * is in reach. Everything runs in the browser; the state stays in it.
 */
type Props = {
  gameName: string;
  packs: GameTrackerPack[];
  initialPackId: string | null;
  initialVariantUid: string | null;
  /** Tests pass a fake engine and archive. */
  deps?: TrackerSessionDeps;
};

const BROWSER_DEPS: TrackerSessionDeps = {
  fetchArchive: fetchTrackerArchive,
  createClient: (onLog: (log: { level: LogLevel; message: string }) => void) =>
    new TrackerClient({ createWorker: createTrackerWorker, onLog }),
};

function isSettings(value: unknown): value is TrackerSettings {
  return (
    typeof value === "object" &&
    value !== null &&
    "showSequenceBreaks" in value &&
    typeof value.showSequenceBreaks === "boolean" &&
    "hideCleared" in value &&
    typeof value.hideCleared === "boolean"
  );
}

function variantOf(pack: GameTrackerPack, wanted: string | null): string {
  const variants = pack.version.variants;
  if (wanted !== null && variants.some((variant) => variant.uid === wanted)) return wanted;
  const fallback = pack.defaultVariantUid;
  if (fallback !== null && variants.some((variant) => variant.uid === fallback)) return fallback;
  return variants[0]?.uid ?? "";
}

/** The choice stays in the address, so a link opens the same tracker. */
function remember(packId: string, variantUid: string) {
  const url = new URL(window.location.href);
  url.searchParams.set("pack", packId);
  url.searchParams.set("variant", variantUid);
  window.history.replaceState(window.history.state, "", url);
}

export function TrackerScreen({ gameName, packs, initialPackId, initialVariantUid, deps = BROWSER_DEPS }: Props) {
  const firstPack = packs.find((pack) => pack.id === initialPackId) ?? packs.find((pack) => pack.isDefault) ?? packs[0];
  const [packId, setPackId] = useState(firstPack?.id ?? "");
  const pack = packs.find((candidate) => candidate.id === packId) ?? firstPack;
  const [variantUid, setVariantUid] = useState(firstPack === undefined ? "" : variantOf(firstPack, initialVariantUid));
  const [generation, setGeneration] = useState(0);

  const storedSettings = useSyncExternalStore(subscribeSettings, readSettingsRaw, () => null);
  const [chosenSettings, setChosenSettings] = useState<TrackerSettings | null>(null);
  const settings = chosenSettings ?? parseSettings(storedSettings, DEFAULT_SETTINGS, isSettings);

  if (pack === undefined) {
    return <p className="text-muted-foreground">Aucun tracker n&apos;est proposé pour ce jeu.</p>;
  }

  function changeSettings(next: TrackerSettings) {
    setChosenSettings(next);
    writeSettings(next);
  }

  function choosePack(id: string) {
    const next = packs.find((candidate) => candidate.id === id);
    if (next === undefined) return;
    const variant = variantOf(next, null);
    setPackId(id);
    setVariantUid(variant);
    remember(id, variant);
  }

  function chooseVariant(uid: string) {
    if (pack === undefined) return;
    setVariantUid(uid);
    remember(pack.id, uid);
  }

  return (
    <TrackerSession
      deps={deps}
      gameName={gameName}
      key={`${pack.version.id}:${variantUid}:${generation}`}
      onChoosePack={choosePack}
      onChooseVariant={chooseVariant}
      onRestart={() => setGeneration((current) => current + 1)}
      onSettings={changeSettings}
      pack={pack}
      packs={packs}
      settings={settings}
      variantUid={variantUid}
    />
  );
}

type SessionProps = {
  gameName: string;
  pack: GameTrackerPack;
  packs: GameTrackerPack[];
  variantUid: string;
  settings: TrackerSettings;
  deps: TrackerSessionDeps;
  onChoosePack: (id: string) => void;
  onChooseVariant: (uid: string) => void;
  onSettings: (settings: TrackerSettings) => void;
  onRestart: () => void;
};

function TrackerSession({
  gameName,
  pack,
  packs,
  variantUid,
  settings,
  deps,
  onChoosePack,
  onChooseVariant,
  onSettings,
  onRestart,
}: SessionProps) {
  const source = useMemo(
    () => ({ versionId: pack.version.id, rootPath: pack.version.rootPath, variantUid }),
    [pack.version.id, pack.version.rootPath, variantUid],
  );
  const session = useTrackerSession(source, deps);
  const [confirmReset, setConfirmReset] = useState(false);
  const { phase } = session;

  return (
    <div className="grid gap-4">
      <header className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <p className="text-sm text-muted-foreground">Tracker</p>
          <h1 className="font-heading text-2xl font-bold text-foreground md:text-3xl">{gameName}</h1>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          {packs.length > 1 ? (
            <SelectField
              label="Pack"
              onChange={onChoosePack}
              options={packs.map((candidate) => ({ value: candidate.id, label: candidate.name }))}
              value={pack.id}
            />
          ) : null}
          {pack.version.variants.length > 1 ? (
            <SelectField
              label="Variante"
              onChange={onChooseVariant}
              options={pack.version.variants.map((variant) => ({ value: variant.uid, label: variant.displayName }))}
              value={variantUid}
            />
          ) : null}
          <button
            className={buttonVariants({ variant: "secondary" })}
            disabled={phase.kind !== "ready"}
            onClick={() => setConfirmReset(true)}
            type="button"
          >
            <RotateCcw aria-hidden="true" className="size-4" />
            Remettre à zéro
          </button>
        </div>
      </header>

      <div className="flex flex-wrap items-center gap-x-5 gap-y-2 text-sm">
        <label className="inline-flex min-h-9 cursor-pointer items-center gap-2 text-foreground">
          <input
            checked={settings.showSequenceBreaks}
            className="size-4 accent-accent-text"
            onChange={(event) => onSettings({ ...settings, showSequenceBreaks: event.target.checked })}
            type="checkbox"
          />
          Afficher les séquences breaks
        </label>
        <label className="inline-flex min-h-9 cursor-pointer items-center gap-2 text-foreground">
          <input
            checked={settings.hideCleared}
            className="size-4 accent-accent-text"
            onChange={(event) => onSettings({ ...settings, hideCleared: event.target.checked })}
            type="checkbox"
          />
          Masquer ce qui est fait
        </label>
        <Legend settings={settings} />
      </div>

      <PackMessages messages={session.messages} />

      <TrackerBody onRetry={onRestart} phase={phase} session={session} settings={settings} />

      <footer className="border-t border-border pt-4">
        <PackCredits pack={pack} />
      </footer>

      <ConfirmDialog
        confirmLabel="Remettre à zéro"
        description="Les objets cochés et les lieux faits de ce tracker seront effacés de ce navigateur."
        icon={RotateCcw}
        onConfirm={() => {
          setConfirmReset(false);
          session.forget();
          onRestart();
        }}
        onOpenChange={setConfirmReset}
        open={confirmReset}
        title="Remettre le tracker à zéro ?"
        tone="danger"
      />
    </div>
  );
}

function Legend({ settings }: { settings: TrackerSettings }) {
  const levels = LEGEND_ORDER.filter((level) => settings.showSequenceBreaks || level !== AccessibilityLevel.SequenceBreak);
  return (
    <ul aria-label="Légende" className="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground">
      {levels.map((level) => (
        <li className="inline-flex items-center gap-1.5" key={level}>
          <span aria-hidden="true" className={`size-3 rounded-sm ${LEVEL_STYLES[level].className}`} />
          {LEVEL_STYLES[level].label}
        </li>
      ))}
    </ul>
  );
}

function PackMessages({ messages }: { messages: PackMessage[] }) {
  if (messages.length === 0) return null;
  return (
    <details className="rounded-lg border border-warning/40 bg-warning/10 px-3 py-2 text-sm">
      <summary className="flex cursor-pointer items-center gap-2 font-semibold text-foreground">
        <AlertTriangle aria-hidden="true" className="size-4 text-warning" />
        Le pack a signalé des erreurs ({messages.length})
      </summary>
      <ul className="mt-2 grid max-h-60 gap-1 overflow-auto font-mono text-xs text-body-foreground">
        {messages.map((message, index) => (
          // Messages only grow, in arrival order: the position is stable.
          <li key={index}>{message.message}</li>
        ))}
      </ul>
    </details>
  );
}

type BodyProps = {
  phase: TrackerSessionPhase;
  session: ReturnType<typeof useTrackerSession>;
  settings: TrackerSettings;
  onRetry: () => void;
};

function TrackerBody({ phase, session, settings, onRetry }: BodyProps) {
  switch (phase.kind) {
    case "download":
      return <Progress phase={phase} />;
    case "prepare":
      return (
        <p className="flex items-center gap-2 py-12 text-muted-foreground" role="status">
          <Loader2 aria-hidden="true" className="size-4 animate-spin" />
          Préparation du pack…
        </p>
      );
    case "failed":
      return (
        <div className="grid justify-items-start gap-3 rounded-lg border border-danger/40 bg-danger/10 p-4" role="alert">
          <p className="text-foreground">{phase.message}</p>
          <button className={buttonVariants({ variant: "secondary" })} onClick={onRetry} type="button">
            Réessayer
          </button>
        </div>
      );
    case "ready":
      return <ReadyTracker phase={phase} session={session} settings={settings} />;
  }
}

function Progress({ phase }: { phase: Extract<TrackerSessionPhase, { kind: "download" }> }) {
  const percent = phase.total === null ? null : Math.min(100, Math.round((phase.loaded / phase.total) * 100));
  return (
    <div className="grid gap-2 py-12" role="status">
      <p className="text-muted-foreground">
        Téléchargement du pack{percent !== null ? ` : ${percent} %` : "…"}
      </p>
      <div className="h-2 w-full max-w-md overflow-hidden rounded-full bg-surface-2">
        <div
          className={`h-full bg-accent-text transition-[width] ${percent === null ? "w-1/3 animate-pulse" : ""}`}
          style={percent === null ? undefined : { width: `${percent}%` }}
        />
      </div>
    </div>
  );
}

type ReadyProps = {
  phase: Extract<TrackerSessionPhase, { kind: "ready" }>;
  session: ReturnType<typeof useTrackerSession>;
  settings: TrackerSettings;
};

function ReadyTracker({ phase, session, settings }: ReadyProps) {
  const { pack, images } = phase;
  const { view, act, reportUnsupported } = session;
  const itemsByCode = useMemo(() => indexItemsByCode(pack.items), [pack.items]);
  const itemsById = useMemo(() => new Map(pack.items.map((item) => [item.id, item])), [pack.items]);
  const context = useMemo<TrackerScreenContext>(
    () => ({ pack, view, itemsByCode, itemsById, settings, images, act, reportUnsupported }),
    [pack, view, itemsByCode, itemsById, settings, images, act, reportUnsupported],
  );
  const root = rootLayoutName(pack);
  const noContextMenu = useCallback((event: React.MouseEvent) => event.preventDefault(), []);

  if (root === null) {
    return <p className="text-muted-foreground">Ce pack n&apos;a pas de disposition à afficher pour cette variante.</p>;
  }
  return (
    <TrackerContextProvider value={context}>
      <div
        className="flex min-h-[70dvh] flex-col lg:h-[calc(100dvh-7rem)] lg:min-h-[32rem]"
        data-testid="tracker-layout"
        onContextMenu={noContextMenu}
      >
        <TrackerLayout name={root} />
      </div>
    </TrackerContextProvider>
  );
}
