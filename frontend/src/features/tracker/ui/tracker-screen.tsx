"use client";

import { AlertTriangle, Loader2, RotateCcw } from "lucide-react";
import { useCallback, useMemo, useRef, useState, useSyncExternalStore } from "react";

import { buttonVariants } from "@/components/ui/button";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { SelectField } from "@/components/ui/select-field";
import { PackCredits } from "@/features/trackers/game-tracker-credits";
import { fetchTrackerArchive, type GameTrackerPack } from "@/features/trackers/tracker-packs-api";

import { createTrackerWorker } from "../engine/create-tracker-worker";
import { TrackerClient } from "../engine/tracker-client";
import { fetchSlotConnection } from "../tracker-slot-api";
import type { CompatibilityReport } from "@/features/admin/admin-tracker-packs-api";

import { createArchipelagoClient } from "./ap-client";
import { useLoadReport } from "./compatibility-report";
import { RoomPanel } from "./room-panel";
import { SlotBanner, useAutoConnect, useSlotConnection } from "./slot-connection";
import { TrackerContextProvider, type TrackerScreenContext } from "./tracker-context";
import { groupMessages } from "./layout-outline";
import { TrackerBoard } from "./tracker-board";
import { FullscreenButton, OptionsMenu } from "./tracker-toolbar";
import {
  parseSettings,
  parseSlotChoice,
  readSettingsRaw,
  readSlotChoiceRaw,
  subscribeSettings,
  subscribeSlotChoice,
  writeSettings,
  writeSlotChoice,
} from "./tracker-storage";
import {
  DEFAULT_SETTINGS,
  indexItemsByCode,
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
  /** Story 54.7: the game of the page, checked against the slot's. */
  gameSlug?: string | null;
  packs: GameTrackerPack[];
  initialPackId: string | null;
  initialVariantUid: string | null;
  /** Story 54.7: the slot the tracker was opened on (`?slot=`), connected without typing anything. */
  slotRef?: string | null;
  /** Story 54.8: set when an admin tries the pack; receives what the load of each variant found. */
  onLoadReport?: (report: CompatibilityReport) => void;
  /** Tests pass a fake engine and archive. */
  deps?: TrackerSessionDeps;
  /** Story 54.10: inside a slot's progression page, which already titles the game: no heading of its own. */
  embedded?: boolean;
};

const BROWSER_DEPS: TrackerSessionDeps = {
  fetchArchive: fetchTrackerArchive,
  createClient: ({ onLog, onApSend }) => new TrackerClient({ createWorker: createTrackerWorker, onLog, onApSend }),
  createApClient: createArchipelagoClient,
  fetchSlotConnection,
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

export function TrackerScreen({
  gameName,
  gameSlug = null,
  packs,
  initialPackId,
  initialVariantUid,
  slotRef = null,
  onLoadReport,
  deps = BROWSER_DEPS,
  embedded = false,
}: Props) {
  // Story 54.7: a slot remembers its pack and variant; the address wins over it.
  const storedChoice = useSyncExternalStore(
    subscribeSlotChoice,
    () => (slotRef === null ? null : readSlotChoiceRaw(slotRef)),
    () => null,
  );
  const remembered = slotRef === null ? null : parseSlotChoice(storedChoice);
  const wantedPackId = initialPackId ?? remembered?.packId ?? null;
  const firstPack = packs.find((pack) => pack.id === wantedPackId) ?? packs.find((pack) => pack.isDefault) ?? packs[0];
  const [chosenPackId, setChosenPackId] = useState<string | null>(null);
  const pack = packs.find((candidate) => candidate.id === chosenPackId) ?? firstPack;
  const [chosenVariant, setChosenVariant] = useState<string | null>(null);
  const wantedVariant = chosenVariant ?? (pack?.id === firstPack?.id ? (initialVariantUid ?? remembered?.variantUid ?? null) : null);
  const variantUid = pack === undefined ? "" : variantOf(pack, wantedVariant);
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

  function choose(packId: string, uid: string) {
    remember(packId, uid);
    if (slotRef !== null) writeSlotChoice(slotRef, { packId, variantUid: uid });
  }

  function choosePack(id: string) {
    const next = packs.find((candidate) => candidate.id === id);
    if (next === undefined) return;
    const variant = variantOf(next, null);
    setChosenPackId(id);
    setChosenVariant(variant);
    choose(id, variant);
  }

  function chooseVariant(uid: string) {
    if (pack === undefined) return;
    setChosenPackId(pack.id);
    setChosenVariant(uid);
    choose(pack.id, uid);
  }

  return (
    <TrackerSession
      deps={deps}
      embedded={embedded}
      gameName={gameName}
      gameSlug={gameSlug}
      key={`${pack.version.id}:${variantUid}:${generation}`}
      onChoosePack={choosePack}
      onLoadReport={onLoadReport}
      onChooseVariant={chooseVariant}
      onRestart={() => setGeneration((current) => current + 1)}
      onSettings={changeSettings}
      pack={pack}
      packs={packs}
      settings={settings}
      slotRef={slotRef}
      variantUid={variantUid}
    />
  );
}

type SessionProps = {
  gameName: string;
  embedded: boolean;
  gameSlug: string | null;
  slotRef: string | null;
  pack: GameTrackerPack;
  packs: GameTrackerPack[];
  variantUid: string;
  settings: TrackerSettings;
  deps: TrackerSessionDeps;
  onChoosePack: (id: string) => void;
  onChooseVariant: (uid: string) => void;
  onSettings: (settings: TrackerSettings) => void;
  onRestart: () => void;
  onLoadReport: ((report: CompatibilityReport) => void) | undefined;
};

function TrackerSession({
  gameName,
  embedded,
  gameSlug,
  slotRef,
  pack,
  packs,
  variantUid,
  settings,
  deps,
  onChoosePack,
  onChooseVariant,
  onSettings,
  onRestart,
  onLoadReport,
}: SessionProps) {
  const source = useMemo(
    () => ({ versionId: pack.version.id, rootPath: pack.version.rootPath, variantUid, slotRef }),
    [pack.version.id, pack.version.rootPath, variantUid, slotRef],
  );
  const session = useTrackerSession(source, deps);
  const [confirmReset, setConfirmReset] = useState(false);
  const { phase } = session;
  const slot = useSlotConnection(slotRef, gameSlug, deps.fetchSlotConnection);
  useAutoConnect(slot, phase.kind === "ready", session);
  useLoadReport(onLoadReport, variantUid, phase, session.messages);

  // Story 54.9: the element shown full screen, the tracker alone.
  const screen = useRef<HTMLDivElement>(null);

  return (
    <div
      className="grid min-w-0 max-w-full gap-3 bg-background [&:fullscreen]:content-start [&:fullscreen]:overflow-auto [&:fullscreen]:p-4"
      ref={screen}
    >
      <header className="flex min-w-0 flex-wrap items-end justify-between gap-3">
        {embedded ? null : (
          <div>
            <p className="text-sm text-muted-foreground">Tracker</p>
            <h1 className="font-heading text-2xl font-bold text-foreground md:text-3xl">{gameName}</h1>
          </div>
        )}
        <div className="flex min-w-0 max-w-full flex-wrap items-center gap-2">
          {packs.length > 1 ? (
            <SelectField
              // A pack's names can be long (« [AP] Map Tracker - Broadcast (F2) Vertical »): cut, never wider than the screen.
              className="max-w-full"
              label="Pack"
              onChange={onChoosePack}
              options={packs.map((candidate) => ({ value: candidate.id, label: candidate.name }))}
              value={pack.id}
            />
          ) : null}
          {pack.version.variants.length > 1 ? (
            <SelectField
              // A pack's names can be long (« [AP] Map Tracker - Broadcast (F2) Vertical »): cut, never wider than the screen.
              className="max-w-full"
              label="Variante"
              onChange={onChooseVariant}
              options={pack.version.variants.map((variant) => ({ value: variant.uid, label: variant.displayName }))}
              value={variantUid}
            />
          ) : null}
          <OptionsMenu
            canReset={phase.kind === "ready"}
            onReset={() => setConfirmReset(true)}
            onSettings={onSettings}
            settings={settings}
          />
          <FullscreenButton target={screen} />
        </div>
      </header>


      {slot !== null ? <SlotBanner slot={slot} /> : null}

      <RoomPanel
        disabled={phase.kind !== "ready"}
        notice={session.roomNotice}
        onConnect={session.connectRoom}
        onDisconnect={session.disconnectRoom}
        status={session.room}
      />

      <PackMessages messages={session.messages} />

      <TrackerBody onRetry={onRestart} phase={phase} session={session} settings={settings} />

      <footer className="border-t border-border pt-4">
        <PackCredits pack={pack} />
      </footer>

      <ConfirmDialog
        confirmLabel="Remettre à zéro"
        description="Les objets cochés et les lieux faits de ce tracker seront effacés de ce navigateur. Une room reliée est déconnectée."
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

function PackMessages({ messages }: { messages: PackMessage[] }) {
  if (messages.length === 0) return null;
  const grouped = groupMessages(messages);
  return (
    <details className="rounded-lg border border-warning/40 bg-warning/10 px-3 py-2 text-sm">
      <summary className="flex cursor-pointer items-center gap-2 font-semibold text-foreground">
        <AlertTriangle aria-hidden="true" className="size-4 text-warning" />
        Le pack a signalé des erreurs ({messages.length})
      </summary>
      <p className="mt-2 text-xs text-muted-foreground">
        Ces erreurs viennent du pack (ses fichiers ou ses scripts), pas du site : le reste du tracker fonctionne.
      </p>
      <ul className="mt-2 grid max-h-60 gap-1 overflow-auto break-words font-mono text-xs text-body-foreground">
        {grouped.map(({ message, count }) => (
          <li key={message}>
            {message}
            {count > 1 ? <span className="ml-1 font-sans font-semibold text-muted-foreground">×{count}</span> : null}
          </li>
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
        className="flex flex-col lg:h-[calc(100dvh-7rem)] lg:min-h-[32rem]"
        data-testid="tracker-layout"
        onContextMenu={noContextMenu}
      >
        <TrackerBoard root={root} />
      </div>
    </TrackerContextProvider>
  );
}
