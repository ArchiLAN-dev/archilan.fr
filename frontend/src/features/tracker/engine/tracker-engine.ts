import { LuaFactory, type LuaEngine } from "wasmoon";

import { PackError } from "./errors";
import { parseItems, type ItemDefinition, type ItemParseIssue, type TrackerItem } from "./items";
import { isJsonObject, readNumber, readString } from "./json-read";
import { AccessibilityLevel, compareVersions, EMULATED_POPTRACKER_VERSION, Highlight } from "./levels";
import { LocationRegistry, type MapLocation, type TrackerLocation, type TrackerSection } from "./locations";
import { LUA_BOOTSTRAP } from "./lua-bootstrap";
import type { PackFiles } from "./pack-files";
import {
  pinKey,
  type EngineAction,
  type LoadedLocation,
  type LoadedMap,
  type LoadedPack,
  type LogLevel,
  type PackManifest,
  type SectionState,
  type StateUpdate,
  type TrackerErrorKind,
} from "./protocol";
import { evaluateRules, type RuleContext } from "./rules";
import type { ApEvent, ApGameNames, ApOutgoing, ApPlayer } from "./ap-events";

/**
 * The PopTracker pack engine (story 54.4): runs a pack's Lua in a sandbox, holds its items and locations, and
 * computes what is accessible. No React, no DOM: the Worker wraps it, Jest drives it directly.
 */
export type EngineLog = { level: LogLevel; message: string };

export type EngineOptions = {
  /** Where the Lua WASM is served (the Worker); Node finds it in `node_modules` by itself. */
  wasmUri?: string;
  onLog?: (log: EngineLog) => void;
  /** Story 54.6: what the pack asks the Archipelago server for, after each batch of events. */
  onApSend?: (packets: ApOutgoing[]) => void;
};

/** A failure that stops the pack, with what the Worker sends to the page. */
export class EngineError extends Error {
  constructor(
    readonly kind: TrackerErrorKind,
    message: string,
    readonly file?: string,
    readonly line?: number,
  ) {
    super(message);
    this.name = "EngineError";
  }
}

type LuaApi = {
  loadScript(path: string): unknown;
  callRule(name: string, ...args: string[]): unknown;
  itemChanged(codes: string): unknown;
  sectionChanged(ref: string): unknown;
  archipelagoHandlerCount(kind: string): unknown;
  ap(json: string): unknown;
  run(source: string): unknown;
  luaProvides(code: string): unknown;
  resetCounts(): unknown;
  frameHandlerCount(): unknown;
  frame(elapsed: number): unknown;
  luaItemFor(code: string): unknown;
  luaItemView(id: number): unknown;
  luaItemClick(id: number, button: string): unknown;
  runAsync(): unknown;
  pendingAsync(): unknown;
};

function isLuaApi(value: unknown): value is LuaApi {
  if (!isJsonObject(value)) return false;
  return [
    "loadScript",
    "callRule",
    "itemChanged",
    "sectionChanged",
    "archipelagoHandlerCount",
    "ap",
    "run",
    "luaProvides",
    "resetCounts",
    "frameHandlerCount",
    "frame",
    "luaItemFor",
    "luaItemView",
    "luaItemClick",
    "runAsync",
    "pendingAsync",
  ].every(
    (key) => typeof value[key] === "function",
  );
}

type Flag = "BulkUpdate" | "AllowDeferredLogicUpdate";

const MAX_WATCH_ROUNDS = 100;
const MAX_LOGS = 500;

/** Passes allowed for rules that need each other to settle; PopTracker has no bound, a broken pack would hang it. */
const MAX_LOGIC_PASSES = 20;
/** Story 54.11: visibility rules, whose `@` references ask for visibility. */
const VISIBILITY = { visibility: true } as const;

/** Rounds of async scripts whose callbacks queue more of them (story 54.8). */
const MAX_ASYNC_ROUNDS = 10;

/** What the screen draws of a Lua item, read from its Lua table. */
type LuaItemView = { name: string; icon: string; badge: string; overlay: string; codes: string[] };

function parseLuaItemView(raw: unknown): LuaItemView | null {
  if (typeof raw !== "string") return null;
  const [name = "", icon = "", badge = "", overlay = "", codes = ""] = raw.split("\u001f");
  return { name, icon, badge, overlay, codes: codes === "" ? [] : codes.split(",") };
}

/** An icon is `path` or `path` and its mods after a line break (`ImageReference:FromImageReference`). */
function splitIcon(icon: string): { img: string; imgMods: string } {
  const [img = "", imgMods = ""] = icon.split("\n");
  return { img, imgMods };
}

function sameLevels(a: Map<string, AccessibilityLevel>, b: Map<string, AccessibilityLevel>): boolean {
  if (a.size !== b.size) return false;
  for (const [key, level] of a) if (b.get(key) !== level) return false;
  return true;
}

export class TrackerEngine {
  readonly logs: EngineLog[] = [];
  /** Functions of the PopTracker API a pack called and the engine does not have (report of story 54.8). */
  readonly unsupportedCalls = new Set<string>();

  private readonly items: TrackerItem[] = [];
  private readonly itemsById = new Map<number, TrackerItem>();
  private readonly itemsByCode = new Map<string, TrackerItem[]>();
  private readonly maps: LoadedMap[] = [];
  private readonly layouts: Record<string, unknown> = {};
  private readonly locations = new LocationRegistry();
  private readonly objectRefs = new Map<TrackerSection | TrackerLocation, string>();
  private readonly objectsByRef = new Map<string, TrackerSection | TrackerLocation>();
  private readonly flags: Record<Flag, boolean> = { BulkUpdate: false, AllowDeferredLogicUpdate: false };

  private readonly levelCache = new Map<string, AccessibilityLevel>();
  private readonly callCache = new Map<string, number | boolean | null>();
  /**
   * Story 54.11: the rule calls that failed, kept until the state changes. The fixed point re-runs the rules up to
   * MAX_LOGIC_PASSES times, and a call that fails (a pack's endless recursion) would fail again at each pass.
   */
  private readonly failedCalls = new Set<string>();
  private currentCallFailed = false;
  private readonly evaluating = new Set<string>();
  /**
   * Story 54.8: the levels of the previous pass while rules that need each other settle (Cuphead: an isle opens with
   * its bosses, a boss needs its isle). A rule met again while it is being evaluated reads this value, as PopTracker
   * reads its cache, and passes repeat until nothing changes.
   */
  private seed = new Map<string, AccessibilityLevel>();
  private loopHit = false;
  /** Story 54.8: items created by `ScriptHost:CreateLuaItem`, by id; their state lives in Lua. */
  private readonly luaItemIds: number[] = [];
  private readonly luaCounts = new Map<string, number>();
  private readonly reported = new Set<string>();
  private readonly pendingItems = new Set<TrackerItem>();
  /** Story 54.11: whether anything changed since the last frame (a frame that changes nothing sends nothing). */
  private changedSinceFrame = false;
  /** Story 54.11: the pack's UI hints, sent with the next state. */
  private pendingHints: { name: string; value: string }[] = [];
  /** Story 54.11: Lua items changed since the last round of watchers (by a click, an Archipelago handler, a watcher). */
  private readonly pendingLuaItems = new Set<number>();
  private readonly pendingSections = new Set<TrackerSection>();
  private previous: StateUpdate = { items: {}, sections: {}, locations: {} };
  private nextItemId = 1;
  /** Story 54.6: the room as the last `connected` event described it. */
  private apGame = "";
  private apPlayers = new Map<number, ApPlayer>();
  private apNames: Record<string, ApGameNames> = {};
  private apOutbox: ApOutgoing[] = [];
  private nextRef = 1;
  private lua: LuaEngine | null = null;
  private api: LuaApi | null = null;

  private readonly ruleContext: RuleContext = {
    providerCount: (code) => this.providerCount(code),
    callFunction: (name, args) => this.callFunction(name, args),
    referenceLevel: (path) => this.referenceLevel(path),
    referenceVisible: (path) => this.referenceVisible(path),
  };

  private constructor(
    private readonly files: PackFiles,
    readonly manifest: PackManifest,
    readonly variantUid: string,
    private readonly options: EngineOptions,
  ) {}

  /** @throws EngineError when the pack cannot be loaded */
  static async load(files: PackFiles, variantUid: string, options: EngineOptions = {}): Promise<TrackerEngine> {
    const manifest = readManifest(files);
    if (manifest.variants.length > 0 && !manifest.variants.some((variant) => variant.uid === variantUid)) {
      throw new EngineError("variant_unknown", `Variante inconnue : ${variantUid}`);
    }
    const engine = new TrackerEngine(files, manifest, variantUid, options);
    // Story 54.11: a pack written for a newer PopTracker may call what the site does not have yet.
    const wanted = manifest.minPopTrackerVersion;
    if (wanted !== null && compareVersions(wanted, EMULATED_POPTRACKER_VERSION) > 0) {
      engine.log("warn", `Le pack demande PopTracker ${wanted} ; le site fait comme PopTracker ${EMULATED_POPTRACKER_VERSION}, certaines fonctions peuvent manquer.`);
    }
    await engine.start();
    return engine;
  }

  pack(): LoadedPack {
    return {
      manifest: this.manifest,
      variantUid: this.variantUid,
      items: [...this.items.map((item) => item.definition), ...this.luaItemDefinitions()],
      maps: this.maps,
      layouts: this.layouts,
      locations: this.locations.allLocations().map((location) => this.describeLocation(location)),
    };
  }

  /** Everything, and the reference for the next diffs. */
  initialState(): StateUpdate {
    this.resolveLevels();
    this.drainAsync();
    this.previous = this.fullState();
    if (this.pendingHints.length === 0) return this.previous;
    const hints = this.pendingHints;
    this.pendingHints = [];
    return { ...this.previous, hints };
  }

  /** @throws EngineError bad_request for an unknown item or section */
  apply(action: EngineAction): StateUpdate {
    this.applyOne(action);
    return this.settle();
  }

  /**
   * Archipelago events (story 54.6), in order, with one logic pass and one round of watchers at the end: the
   * `connected` of an advanced game brings hundreds of items and locations at once.
   */
  applyAp(events: readonly ApEvent[]): StateUpdate {
    const api = this.luaApi();
    for (const event of events) {
      if (event.kind === "connected") {
        this.apGame = event.game;
        this.apPlayers = new Map(event.players.map((player) => [player.slot, player]));
        this.apNames = event.names;
        // The name tables stay on this side: the pack reads them through `GetItemName` and the like.
        api.ap(JSON.stringify({ ...event, players: [], names: {} }));
        continue;
      }
      try {
        api.ap(JSON.stringify(event));
      } catch (error) {
        this.log("error", `Événement Archipelago ${event.kind} : ${error instanceof Error ? error.message : String(error)}`);
      }
    }
    const state = this.settle();
    if (this.apOutbox.length > 0) {
      const packets = this.apOutbox;
      this.apOutbox = [];
      this.options.onApSend?.(packets);
    }
    return state;
  }

  /** Story 54.11: whether the pack has frame handlers (`ScriptHost:AddOnFrameHandler`), for the Worker's clock. */
  hasFrameHandlers(): boolean {
    const count = this.luaApi().frameHandlerCount();
    return typeof count === "number" && count > 0;
  }

  /**
   * Story 54.11: one frame, as PopTracker calls its frame handlers; `null` when they changed nothing (most frames),
   * so the Worker sends nothing.
   */
  frame(elapsedSeconds: number): StateUpdate | null {
    this.changedSinceFrame = false;
    this.luaApi().frame(elapsedSeconds);
    if (!this.changedSinceFrame && this.pendingHints.length === 0) return null;
    return this.settle();
  }

  /** Several actions, with one logic pass and one round of watchers at the end. */
  applyBulk(actions: readonly EngineAction[]): StateUpdate {
    for (const action of actions) this.applyOne(action);
    return this.settle();
  }

  /** The bytes of a pack file (images), variant folder first. */
  file(path: string): Uint8Array | null {
    return this.files.bytes(path);
  }

  /** Lua run as the pack would run it: for tests and checks. */
  runLua(source: string): unknown {
    return this.luaApi().run(source);
  }

  archipelagoHandlerCount(kind: string): number {
    const count = this.luaApi().archipelagoHandlerCount(kind);
    return typeof count === "number" ? count : 0;
  }

  sectionLevel(path: string): AccessibilityLevel | null {
    const section = this.locations.findSection(path);
    return section === undefined ? null : this.sectionDisplayLevel(section);
  }

  findItem(code: string): TrackerItem | undefined {
    return this.itemsByCode.get(code)?.[0];
  }

  dispose(): void {
    this.lua?.global.close();
    this.lua = null;
    this.api = null;
  }

  // Loading -----------------------------------------------------------------

  private async start(): Promise<void> {
    const factory = new LuaFactory(this.options.wasmUri);
    const lua = await factory.createEngine({ openStandardLibs: true, injectObjects: false, enableProxy: false });
    this.lua = lua;
    lua.global.set("__pt_bridge", this.bridge());
    const api: unknown = lua.doStringSync(LUA_BOOTSTRAP);
    if (!isLuaApi(api)) throw new EngineError("lua_error", "Le moteur Lua n'a pas démarré.");
    this.api = api;
    if (this.files.resolve("scripts/init.lua") === null) {
      throw new EngineError("file_missing", "Le pack n'a pas de scripts/init.lua.", "scripts/init.lua");
    }
    try {
      api.loadScript("scripts/init.lua");
    } catch (error) {
      // PopTracker logs an error of init.lua and keeps what was loaded before it (story 54.8): Spyro 3 calls a
      // function that does not exist after declaring all of its items and maps.
      const failure = this.luaFailure(error);
      if (this.items.length === 0 && Object.keys(this.layouts).length === 0) throw failure;
      const where = failure.file === undefined ? "" : ` (${failure.file}${failure.line === undefined ? "" : `:${failure.line}`})`;
      this.log("error", `${failure.message}${where}`);
    }
    this.settleWatchers();
  }

  /** What the Lua shim may call. Only strings, numbers and booleans cross; nothing here returns a JS object. */
  private bridge(): Record<string, (...args: unknown[]) => unknown> {
    return {
      variant: () => this.variantUid,
      popVersion: () => EMULATED_POPTRACKER_VERSION,
      log: (level, message) => this.log(level === "error" || level === "warn" ? level : "info", String(message)),
      add: (kind, file) => (typeof kind === "string" && typeof file === "string" ? this.addFile(kind, file) : false),
      readScript: (path) => (typeof path === "string" ? (this.files.text(path) ?? undefined) : undefined),
      count: (code) => (typeof code === "string" ? this.providerCount(code) : 0),
      find: (code) => (typeof code === "string" ? this.findRef(code) : undefined),
      get: (ref, key) => (typeof ref === "string" && typeof key === "string" ? this.getProperty(ref, key) : undefined),
      set: (ref, key, value) => {
        if (typeof ref === "string" && typeof key === "string") this.setProperty(ref, key, value);
      },
      unsupported: (name) => {
        const label = String(name);
        if (!this.unsupportedCalls.has(label)) this.log("error", `Fonction non prise en charge : ${label}`);
        this.unsupportedCalls.add(label);
      },
      flag: (name) => (name === "BulkUpdate" || name === "AllowDeferredLogicUpdate" ? this.flags[name] : false),
      setFlag: (name, value) => {
        if (name === "BulkUpdate" || name === "AllowDeferredLogicUpdate") this.flags[name] = value === true;
      },
      apSend: (cmd, key) => {
        if ((cmd !== "Get" && cmd !== "SetNotify") || typeof key !== "string") return;
        const last = this.apOutbox.at(-1);
        if (last !== undefined && last.cmd === cmd) last.keys.push(key);
        else this.apOutbox.push({ cmd, keys: [key] });
      },
      apName: (kind, id, game) => this.apName(kind, id, game),
      apPlayer: (field, slot) => {
        const player = typeof slot === "number" ? this.apPlayers.get(slot) : undefined;
        if (player === undefined) return undefined;
        return field === "alias" ? player.alias : field === "game" ? player.game : undefined;
      },
      uiHint: (name, value) => {
        if (typeof name !== "string") return;
        this.pendingHints.push({ name, value: typeof value === "string" ? value : "" });
        if (name !== "ActivateTab") this.logOnce(`hint:${name.split(" ")[0]}`, "info", `Tracker:UiHint « ${name} » ignoré`);
      },
      luaItemCreate: () => {
        const id = this.nextItemId++;
        this.luaItemIds.push(id);
        return id;
      },
      // Story 54.11, as PopTracker: a Lua item that changes, whoever changes it, re-runs the logic and the watchers.
      luaItemChanged: (id) => {
        if (typeof id !== "number") return;
        this.pendingLuaItems.add(id);
        this.invalidate();
      },
      ruleError: (name, message) => {
        this.currentCallFailed = true;
        this.logOnce(`rule:${String(name)}`, "error", `Règle $${String(name)} : ${String(message)}`);
      },
    };
  }

  private apName(kind: unknown, id: unknown, game: unknown): string | undefined {
    if (typeof id !== "number") return undefined;
    const names = this.apNames[typeof game === "string" && game !== "" ? game : this.apGame];
    if (names === undefined) return undefined;
    return kind === "item" ? names.items[id] : kind === "location" ? names.locations[id] : undefined;
  }

  private addFile(kind: string, file: string): boolean {
    let json: unknown;
    try {
      json = this.files.json(file);
    } catch (error) {
      this.log("error", error instanceof PackError ? error.message : `Lecture impossible : ${file}`);
      return false;
    }
    switch (kind) {
      case "items":
        return this.addItems(json, file);
      case "maps":
        return this.addMaps(json, file);
      case "locations": {
        const issues: { message: string }[] = [];
        this.locations.add(json, issues);
        for (const issue of issues) this.log("warn", `${file} : ${issue.message}`);
        this.invalidate();
        return true;
      }
      case "layouts":
        if (!isJsonObject(json)) {
          this.log("error", `${file} : un fichier de layouts est un objet.`);
          return false;
        }
        // A file may wrap its layouts in a `layouts` key (`tracker.jsonc` of Majora's Mask), as PopTracker accepts.
        for (const [name, layout] of Object.entries(json)) {
          if (name === "layouts" && isJsonObject(layout)) Object.assign(this.layouts, layout);
          else this.layouts[name] = layout;
        }
        return true;
      default:
        return false;
    }
  }

  private addItems(json: unknown, file: string): boolean {
    const issues: ItemParseIssue[] = [];
    const added = parseItems(json, () => this.nextItemId++, (code) => this.findItem(code), issues);
    for (const issue of issues) this.log("warn", `${file} : ${issue.message}`);
    for (const item of added) {
      this.items.push(item);
      this.itemsById.set(item.definition.id, item);
      for (const code of item.codes()) {
        const list = this.itemsByCode.get(code) ?? [];
        list.push(item);
        this.itemsByCode.set(code, list);
      }
    }
    this.invalidate();
    return true;
  }

  private addMaps(json: unknown, file: string): boolean {
    if (!Array.isArray(json)) {
      this.log("error", `${file} : un fichier de cartes est une liste.`);
      return false;
    }
    for (const raw of json) {
      if (!isJsonObject(raw)) continue;
      const name = readString(raw, "name");
      if (name === null) continue;
      this.maps.push({
        name,
        img: readString(raw, "img"),
        locationSize: readNumber(raw, "location_size"),
        locationBorderThickness: readNumber(raw, "location_border_thickness"),
        locationShape: readString(raw, "location_shape"),
      });
    }
    return true;
  }

  // Lua objects -------------------------------------------------------------

  private findRef(code: string): string | undefined {
    if (code.startsWith("@")) {
      const target = code.slice(1);
      const section = this.locations.findSection(target);
      if (section !== undefined) {
        const resolved = this.locations.resolveRef(section);
        return resolved === undefined ? undefined : this.refFor(resolved);
      }
      const location = this.locations.findLocation(target);
      return location === undefined ? undefined : this.refFor(location);
    }
    const item = this.findItem(code);
    return item === undefined ? undefined : `i${item.definition.id}`;
  }

  private refFor(object: TrackerSection | TrackerLocation): string {
    const existing = this.objectRefs.get(object);
    if (existing !== undefined) return existing;
    const ref = `${"sections" in object ? "l" : "s"}${this.nextRef++}`;
    this.objectRefs.set(object, ref);
    this.objectsByRef.set(ref, object);
    return ref;
  }

  private itemForRef(ref: string): TrackerItem | undefined {
    return ref.startsWith("i") ? this.itemsById.get(Number(ref.slice(1))) : undefined;
  }

  private getProperty(ref: string, key: string): unknown {
    const item = this.itemForRef(ref);
    if (item !== undefined) return itemProperty(item, key);
    const object = this.objectsByRef.get(ref);
    if (object === undefined) return undefined;
    if ("sections" in object) {
      if (key === "AccessibilityLevel") return this.locationLevel(object, false);
      if (key === "Name") return object.name;
      if (key === "FullID") return object.path;
      return undefined;
    }
    switch (key) {
      case "ChestCount":
        return object.itemCount;
      case "AvailableChestCount":
        return object.available;
      case "AccessibilityLevel":
        return this.sectionDisplayLevel(object);
      case "Highlight":
        return object.highlight;
      case "FullID":
        return object.path;
      case "Name":
        return object.name;
      default:
        return undefined;
    }
  }

  private setProperty(ref: string, key: string, value: unknown): void {
    const item = this.itemForRef(ref);
    if (item !== undefined) {
      const before = JSON.stringify(item.state());
      setItemProperty(item, key, value);
      if (JSON.stringify(item.state()) !== before) this.markItemChanged(item);
      return;
    }
    const object = this.objectsByRef.get(ref);
    if (object === undefined || "sections" in object) return;
    if (key === "AvailableChestCount" && typeof value === "number") {
      const available = Math.min(Math.max(Math.trunc(value), 0), object.itemCount);
      if (available !== object.available) {
        object.available = available;
        this.markSectionChanged(object);
      }
    } else if (key === "Highlight" && typeof value === "number") {
      object.highlight = value in HIGHLIGHT_BY_VALUE ? HIGHLIGHT_BY_VALUE[value] : Highlight.None;
      this.markSectionChanged(object);
    }
  }

  // Actions -----------------------------------------------------------------

  private applyOne(action: EngineAction): void {
    switch (action.type) {
      case "itemClick": {
        if (this.luaItemIds.includes(action.itemId)) {
          this.luaApi().luaItemClick(action.itemId, action.button);
          this.pendingLuaItems.add(action.itemId);
          this.invalidate();
          return;
        }
        const item = this.requireItem(action.itemId);
        for (const changed of item.click(action.button, (id) => this.itemsById.get(id))) this.markItemChanged(changed);
        return;
      }
      case "itemSet": {
        // A Lua item keeps its own state (its SaveFunc is not read yet): nothing to put back.
        if (this.luaItemIds.includes(action.itemId)) return;
        const item = this.requireItem(action.itemId);
        if (action.state.active !== undefined) item.setActive(action.state.active);
        if (action.state.stage !== undefined) item.setStage(action.state.stage);
        if (action.state.count !== undefined) item.setCount(action.state.count);
        this.markItemChanged(item);
        return;
      }
      case "sectionClear": {
        const section = this.requireSection(action.sectionId);
        if (section.itemCount > 0) {
          const count = action.count ?? (section.clearAsGroup ? section.available : 1);
          section.available = Math.max(0, section.available - Math.max(0, Math.trunc(count)));
        }
        if (section.itemCount === 0 || section.available === 0) this.setHosted(section, true);
        this.markSectionChanged(section);
        return;
      }
      case "sectionUnclear": {
        const section = this.requireSection(action.sectionId);
        if (section.itemCount > 0) {
          section.available = section.clearAsGroup ? section.itemCount : Math.min(section.itemCount, section.available + 1);
        }
        this.setHosted(section, false);
        this.markSectionChanged(section);
        return;
      }
    }
  }

  private requireItem(id: number): TrackerItem {
    const item = this.itemsById.get(id);
    if (item === undefined) throw new EngineError("bad_request", `Item inconnu : ${id}`);
    return item;
  }

  private requireSection(id: number): TrackerSection {
    const found = this.locations.sectionById(id);
    const section = found === undefined ? undefined : this.locations.resolveRef(found);
    if (section === undefined) throw new EngineError("bad_request", `Section inconnue : ${id}`);
    return section;
  }

  private setHosted(section: TrackerSection, active: boolean): void {
    for (const code of section.hostedItems) {
      const item = this.findItem(code);
      if (item === undefined || item.isActive() === active) continue;
      item.setActive(active);
      this.markItemChanged(item);
    }
  }

  private markItemChanged(item: TrackerItem): void {
    this.pendingItems.add(item);
    this.invalidate();
  }

  private markSectionChanged(section: TrackerSection): void {
    this.pendingSections.add(section);
    this.invalidate();
  }

  private invalidate(): void {
    this.changedSinceFrame = true;
    this.levelCache.clear();
    this.callCache.clear();
    this.failedCalls.clear();
    this.luaCounts.clear();
    this.seed = new Map();
    this.api?.resetCounts();
  }

  /** Evaluates every rule, again while some rule met itself, until the levels stop changing. */
  private resolveLevels(): void {
    for (let pass = 0; pass < MAX_LOGIC_PASSES; pass += 1) {
      this.loopHit = false;
      for (const location of this.locations.allLocations()) {
        this.locationAccess(location);
        this.locationVisible(location);
      }
      for (const section of this.locations.allSections()) {
        this.sectionLogicLevel(section);
        this.sectionVisible(section);
      }
      if (!this.loopHit) return;
      const levels = new Map(this.levelCache);
      if (pass > 0 && sameLevels(levels, this.seed)) return;
      this.seed = levels;
      this.levelCache.clear();
      this.callCache.clear();
    }
    this.logOnce("logic-passes", "warn", "La logique du pack ne se stabilise pas : certains lieux peuvent être faux.");
  }

  private settle(): StateUpdate {
    this.settleWatchers();
    this.resolveLevels();
    this.drainAsync();
    return this.diff();
  }

  /**
   * Story 54.8: the async scripts the last logic pass queued run now, as PopTracker runs them on the next frame; what
   * their callbacks change is settled before the screen hears of it.
   */
  private drainAsync(): void {
    const api = this.luaApi();
    for (let round = 0; round < MAX_ASYNC_ROUNDS; round += 1) {
      if (api.pendingAsync() === 0) return;
      api.runAsync();
      this.invalidate();
      this.settleWatchers();
      this.resolveLevels();
    }
    this.logOnce("async-rounds", "warn", "Les scripts asynchrones du pack se relancent sans fin : arrêt.");
  }

  private luaItemView(id: number): LuaItemView | null {
    return parseLuaItemView(this.luaApi().luaItemView(id));
  }

  /** Lua items as the screen draws them: a static item whose image, name and badge come with its state. */
  private luaItemDefinitions(): ItemDefinition[] {
    const named = this.luaItemsNamedByLayouts();
    return this.luaItemIds.flatMap((id) => {
      const found = this.luaItemView(id);
      if (found === null) return [];
      const view = { ...found, codes: [...new Set([...found.codes, ...(named.get(id) ?? [])])] };
      const { img, imgMods } = splitIcon(view.icon);
      const definition: ItemDefinition = {
        id,
        name: view.name,
        type: "static",
        stages: [{ img: img === "" ? null : img, imgMods, disabledImg: null, disabledImgMods: null, name: null, codes: view.codes }],
        minQuantity: 0,
        maxQuantity: 0,
        baseItemId: null,
        leftItemId: null,
        rightItemId: null,
        compositeImages: [],
        overlayBackground: null,
        overlayFontSize: null,
      };
      return [definition];
    });
  }

  /**
   * Story 54.11: the codes the layouts name that no JSON item gives, by the Lua item that gives them. A Lua item may
   * answer through CanProvideCodeFunc without PotentialCodes; the screen, which cannot ask Lua, finds it by these.
   */
  private luaItemsNamedByLayouts(): Map<number, string[]> {
    const named = new Map<number, string[]>();
    if (this.luaItemIds.length === 0) return named;
    const api = this.luaApi();
    for (const code of layoutItemCodes(this.layouts)) {
      if (this.itemsByCode.has(code)) continue;
      const id = api.luaItemFor(code);
      if (typeof id !== "number") continue;
      named.set(id, [...(named.get(id) ?? []), code]);
    }
    return named;
  }

  /** Watchers run after the change, outside `BulkUpdate`; what they change triggers the next round. */
  private settleWatchers(): void {
    const api = this.luaApi();
    for (let round = 0; round < MAX_WATCH_ROUNDS; round++) {
      if (this.flags.BulkUpdate) return;
      if (this.pendingItems.size === 0 && this.pendingSections.size === 0 && this.pendingLuaItems.size === 0) return;
      const items = [...this.pendingItems];
      const luaItems = [...this.pendingLuaItems];
      const sections = [...this.pendingSections];
      this.pendingItems.clear();
      this.pendingLuaItems.clear();
      this.pendingSections.clear();
      try {
        // Story 54.11: every code the item can provide, the item-level codes of a progressive included.
        for (const item of items) api.itemChanged(item.codes().join(","));
        for (const id of luaItems) api.itemChanged((this.luaItemView(id)?.codes ?? []).join(","));
        for (const section of sections) api.sectionChanged(this.refFor(section));
      } catch (error) {
        this.log("error", error instanceof Error ? error.message : String(error));
      }
    }
    this.pendingItems.clear();
    this.pendingLuaItems.clear();
    this.pendingSections.clear();
    this.log("error", "Les watchers du pack se relancent sans fin : arrêt.");
  }

  // Logic -------------------------------------------------------------------

  /** Story 54.8: what the Lua items provide of a code, once per logic pass. */
  private luaProvidedCount(code: string): number {
    if (this.luaItemIds.length === 0) return 0;
    const cached = this.luaCounts.get(code);
    if (cached !== undefined) return cached;
    const raw = this.luaApi().luaProvides(code);
    const count = typeof raw === "number" ? raw : 0;
    this.luaCounts.set(code, count);
    return count;
  }

  private providerCount(code: string): number {
    const items = this.itemsByCode.get(code);
    if (items === undefined) return this.luaProvidedCount(code);
    let total = 0;
    for (const item of items) total += item.providedCount(code, (id) => this.itemsById.get(id));
    return total + this.luaProvidedCount(code);
  }

  private callFunction(name: string, args: string[]): number | boolean | null {
    const key = `${name}\u0000${args.join("\u0001")}`;
    const cached = this.callCache.get(key);
    if (cached !== undefined) return cached;
    if (this.failedCalls.has(key)) return null;
    this.currentCallFailed = false;
    let value: number | boolean | null = null;
    try {
      const result = this.luaApi().callRule(name, ...args);
      if (typeof result === "number" || typeof result === "boolean") value = result;
    } catch (error) {
      this.currentCallFailed = true;
      this.logOnce(`rule:${name}`, "error", `Règle $${name} : ${error instanceof Error ? error.message : String(error)}`);
    }
    if (this.currentCallFailed) this.failedCalls.add(key);
    this.callCache.set(key, value);
    return value;
  }

  private referenceLevel(path: string): AccessibilityLevel {
    const section = this.locations.findSection(path);
    if (section !== undefined) return this.sectionLogicLevel(section);
    const location = this.locations.findLocation(path);
    if (location !== undefined) return this.locationAccess(location);
    this.logOnce(`ref:${path}`, "warn", `Référence introuvable : @${path}`);
    return AccessibilityLevel.None;
  }

  /** Story 54.11: an `@` in visibility rules, as PopTracker: the target's visibility; a missing target is hidden. */
  private referenceVisible(path: string): boolean {
    const section = this.locations.findSection(path);
    if (section !== undefined) return this.sectionVisible(section);
    const location = this.locations.findLocation(path);
    if (location !== undefined) return this.locationVisible(location);
    this.logOnce(`ref:${path}`, "warn", `Référence introuvable : @${path}`);
    return false;
  }

  /** Memoized, with loop detection: a rule that needs itself is not accessible. */
  private cached(key: string, label: string, compute: () => AccessibilityLevel): AccessibilityLevel {
    const hit = this.levelCache.get(key);
    if (hit !== undefined) return hit;
    if (this.evaluating.has(key)) {
      this.loopHit = true;
      this.logOnce(`loop:${key}`, "info", `Règles circulaires autour de ${label}`);
      return this.seed.get(key) ?? AccessibilityLevel.None;
    }
    this.evaluating.add(key);
    try {
      const level = compute();
      this.levelCache.set(key, level);
      return level;
    } finally {
      this.evaluating.delete(key);
    }
  }

  /** The access rules of a location and of all its ancestors (AND). */
  private locationAccess(location: TrackerLocation): AccessibilityLevel {
    return this.cached(`a:${location.id}`, location.name, () => {
      const own = evaluateRules(location.accessRules, this.ruleContext, { inspectableSequenceBreak: location.inspectableSequenceBreak });
      if (location.parent === null || own === AccessibilityLevel.None) return own;
      return Math.min(own, this.locationAccess(location.parent)) as AccessibilityLevel;
    });
  }

  private locationVisible(location: TrackerLocation): boolean {
    const level = this.cached(`v:${location.id}`, location.name, () => {
      const own = evaluateRules(location.visibilityRules, this.ruleContext, VISIBILITY);
      if (location.parent === null || own === AccessibilityLevel.None) return own;
      return this.locationVisible(location.parent) ? own : AccessibilityLevel.None;
    });
    return level !== AccessibilityLevel.None;
  }

  /** Accessibility from the rules only, whether the section is cleared or not. */
  private sectionLogicLevel(section: TrackerSection): AccessibilityLevel {
    const target = this.locations.resolveRef(section);
    if (target === undefined) return AccessibilityLevel.None;
    return this.cached(`s:${target.id}`, `${target.location.name}/${target.name}`, () => {
      const own = evaluateRules(target.accessRules, this.ruleContext, { inspectableSequenceBreak: target.inspectableSequenceBreak });
      if (own === AccessibilityLevel.None) return own;
      return Math.min(own, this.locationAccess(target.location)) as AccessibilityLevel;
    });
  }

  private sectionVisible(section: TrackerSection): boolean {
    const target = this.locations.resolveRef(section);
    if (target === undefined) return false;
    const level = this.cached(`w:${target.id}`, `${target.location.name}/${target.name}`, () =>
      this.locationVisible(target.location) ? evaluateRules(target.visibilityRules, this.ruleContext, VISIBILITY) : AccessibilityLevel.None,
    );
    return level !== AccessibilityLevel.None;
  }

  private isCleared(section: TrackerSection): boolean {
    if (section.itemCount > 0) return section.available === 0;
    if (section.hostedItems.length === 0) return true;
    return section.hostedItems.every((code) => this.providerCount(code) > 0);
  }

  private sectionDisplayLevel(section: TrackerSection): AccessibilityLevel {
    const target = this.locations.resolveRef(section);
    if (target === undefined) return AccessibilityLevel.None;
    return this.isCleared(target) ? AccessibilityLevel.Cleared : this.sectionLogicLevel(target);
  }

  /**
   * A location from its visible sections: `Cleared` when all are done, `Partial` when only some of the remaining
   * ones are reachable, else their common (lowest) level. Without sections, its own access rules.
   */
  private locationLevel(location: TrackerLocation, withCleared: boolean): AccessibilityLevel {
    if (location.sections.length === 0) return this.locationAccess(location);
    let lowest: AccessibilityLevel | null = null;
    let highest: AccessibilityLevel | null = null;
    for (const section of location.sections) {
      if (!this.sectionVisible(section)) continue;
      const target = this.locations.resolveRef(section);
      if (target === undefined || (withCleared && this.isCleared(target))) continue;
      const level = this.sectionLogicLevel(target);
      lowest = lowest === null || level < lowest ? level : lowest;
      highest = highest === null || level > highest ? level : highest;
    }
    if (lowest === null || highest === null) return withCleared ? AccessibilityLevel.Cleared : AccessibilityLevel.None;
    if (lowest === AccessibilityLevel.None && highest > AccessibilityLevel.None) return AccessibilityLevel.Partial;
    return lowest;
  }

  // State -------------------------------------------------------------------

  private fullState(): StateUpdate {
    this.resolveLevels();
    const state: StateUpdate = { items: {}, sections: {}, locations: {} };
    for (const item of this.items) state.items[item.definition.id] = item.state();
    for (const id of this.luaItemIds) {
      const view = this.luaItemView(id);
      if (view === null) continue;
      const icon = splitIcon(view.icon);
      state.items[id] = {
        active: true,
        stage: 0,
        count: 0,
        overlay: view.overlay !== "" ? view.overlay : view.badge.trim(),
        ...(icon.img === "" ? {} : { icon }),
        ...(view.name === "" ? {} : { name: view.name }),
      };
    }
    for (const section of this.locations.allSections()) {
      if (section.ref !== null) continue;
      state.sections[section.id] = this.sectionState(section);
    }
    const pins: Record<string, boolean> = {};
    for (const location of this.locations.allLocations()) {
      if (location.sections.length > 0) state.locations[location.id] = this.locationLevel(location, true);
      location.mapLocations.forEach((spot, index) => {
        if (spot.restrictVisibilityRules.length === 0 && spot.forceInvisibilityRules.length === 0) return;
        pins[pinKey(location.id, index)] = this.pinVisible(spot);
      });
    }
    if (Object.keys(pins).length > 0) state.pins = pins;
    return state;
  }

  /** Story 54.11, as PopTracker's `Tracker::isVisible(MapLocation)`: hidden when a force rule holds, else its own rules. */
  private pinVisible(spot: MapLocation): boolean {
    if (spot.forceInvisibilityRules.length > 0 && evaluateRules(spot.forceInvisibilityRules, this.ruleContext, VISIBILITY) !== AccessibilityLevel.None) {
      return false;
    }
    return evaluateRules(spot.restrictVisibilityRules, this.ruleContext, VISIBILITY) !== AccessibilityLevel.None;
  }

  private sectionState(section: TrackerSection): SectionState {
    return {
      available: section.available,
      level: this.sectionDisplayLevel(section),
      visible: this.sectionVisible(section),
      highlight: section.highlight,
    };
  }

  private diff(): StateUpdate {
    const next = this.fullState();
    const changes: StateUpdate = { items: {}, sections: {}, locations: {} };
    for (const [id, state] of Object.entries(next.items)) {
      const before = this.previous.items[Number(id)];
      if (before === undefined || JSON.stringify(before) !== JSON.stringify(state)) changes.items[Number(id)] = state;
    }
    for (const [id, state] of Object.entries(next.sections)) {
      const before = this.previous.sections[Number(id)];
      if (before === undefined || JSON.stringify(before) !== JSON.stringify(state)) changes.sections[Number(id)] = state;
    }
    for (const [id, level] of Object.entries(next.locations)) {
      if (this.previous.locations[Number(id)] !== level) changes.locations[Number(id)] = level;
    }
    for (const [key, visible] of Object.entries(next.pins ?? {})) {
      if (this.previous.pins?.[key] !== visible) changes.pins = { ...changes.pins, [key]: visible };
    }
    if (this.pendingHints.length > 0) {
      changes.hints = this.pendingHints;
      this.pendingHints = [];
    }
    this.previous = next;
    return changes;
  }

  private describeLocation(location: TrackerLocation): LoadedLocation {
    return {
      id: location.id,
      path: location.path,
      name: location.name,
      parentId: location.parent?.id ?? null,
      mapLocations: location.mapLocations,
      images: location.images,
      overlayBackground: location.overlayBackground,
      sections: location.sections.map((section) => ({
        id: section.id,
        path: section.path,
        name: section.name,
        itemCount: section.itemCount,
        hostedItems: section.hostedItems,
        clearAsGroup: section.clearAsGroup,
        refId: section.ref === null ? null : (this.locations.resolveRef(section)?.id ?? null),
        images: section.images,
      })),
    };
  }

  // Plumbing ----------------------------------------------------------------

  private luaApi(): LuaApi {
    if (this.api === null) throw new EngineError("not_loaded", "Le pack n'est pas chargé.");
    return this.api;
  }

  private luaFailure(error: unknown): EngineError {
    if (error instanceof EngineError) return error;
    const message = error instanceof Error ? error.message : String(error);
    const unsupported = /unsupported_api: (\S+)/.exec(message);
    if (unsupported !== null) return new EngineError("unsupported_api", `Fonction non prise en charge : ${unsupported[1]}`);
    const located = /^(?:Error: )?([^\s:][^:]*\.lua):(\d+): ([\s\S]*)$/.exec(message);
    if (located !== null) return new EngineError("lua_error", located[3], located[1], Number(located[2]));
    return new EngineError("lua_error", message);
  }

  private log(level: LogLevel, message: string): void {
    const entry = { level, message };
    if (this.logs.length < MAX_LOGS) this.logs.push(entry);
    this.options.onLog?.(entry);
  }

  private logOnce(key: string, level: LogLevel, message: string): void {
    if (this.reported.has(key)) return;
    this.reported.add(key);
    this.log(level, message);
  }
}

const HIGHLIGHT_BY_VALUE: Record<number, Highlight> = Object.fromEntries(
  Object.values(Highlight).map((value) => [value, value]),
);

function itemProperty(item: TrackerItem, key: string): unknown {
  switch (key) {
    case "Active":
      return item.isActive();
    case "CurrentStage":
      return item.stage;
    case "AcquiredCount":
      return item.count;
    case "MinCount":
      return item.minCount;
    case "MaxCount":
      return item.maxCount;
    case "Increment":
      return item.increment;
    case "Decrement":
      return item.decrement;
    case "Name":
      return item.definition.name;
    case "Type":
      return item.type;
    case "BadgeText":
      return item.overlay;
    default:
      return undefined;
  }
}

function setItemProperty(item: TrackerItem, key: string, value: unknown): void {
  const number = typeof value === "number" ? value : null;
  switch (key) {
    case "Active":
      item.setActive(value !== false && value !== null && value !== undefined);
      return;
    case "CurrentStage":
      if (number !== null) item.setStage(number);
      return;
    case "AcquiredCount":
      if (number !== null) item.setCount(number);
      return;
    case "MinCount":
      if (number !== null) {
        item.minCount = Math.trunc(number);
        item.setCount(item.count);
      }
      return;
    case "MaxCount":
      if (number !== null) {
        item.maxCount = Math.trunc(number);
        item.setCount(item.count);
      }
      return;
    case "Increment":
      if (number !== null) item.increment = Math.trunc(number);
      return;
    case "Decrement":
      if (number !== null) item.decrement = Math.trunc(number);
      return;
    case "BadgeText":
      item.overlay = typeof value === "string" ? value : "";
      return;
    case "Icon":
      item.overrideIcon(typeof value === "string" && value !== "" ? splitIcon(value) : null);
      return;
    default:
      return;
  }
}

/** @throws EngineError manifest_invalid */
function readManifest(files: PackFiles): PackManifest {
  let json: unknown;
  try {
    json = files.json("manifest.json");
  } catch (error) {
    throw new EngineError("manifest_invalid", error instanceof Error ? error.message : "manifest.json illisible.");
  }
  if (!isJsonObject(json)) throw new EngineError("manifest_invalid", "manifest.json n'est pas un objet.");
  const variants = isJsonObject(json.variants)
    ? Object.entries(json.variants).flatMap(([uid, raw]) =>
        isJsonObject(raw)
          ? [
              {
                uid,
                displayName: readString(raw, "display_name") ?? uid,
                flags: Array.isArray(raw.flags) ? raw.flags.filter((flag): flag is string => typeof flag === "string") : [],
              },
            ]
          : [],
      )
    : [];
  return {
    name: readString(json, "name") ?? "",
    gameName: readString(json, "game_name") ?? "",
    packageUid: readString(json, "package_uid") ?? "",
    packageVersion: readString(json, "package_version") ?? "",
    platform: readString(json, "platform") ?? "",
    variants,
    minPopTrackerVersion: readString(json, "min_poptracker_version"),
    targetPopTrackerVersion: readString(json, "target_poptracker_version"),
  };
}


/** Story 54.11: every item code a layout names (`item` widgets, `itemgrid` rows), however deep. */
export function layoutItemCodes(layouts: Record<string, unknown>): Set<string> {
  const codes = new Set<string>();
  const seen = new Set<unknown>();
  const visit = (node: unknown): void => {
    if (typeof node !== "object" || node === null || seen.has(node)) return;
    seen.add(node);
    if (Array.isArray(node)) {
      for (const child of node) visit(child);
      return;
    }
    if (!isJsonObject(node)) return;
    const record = node;
    if (record.type === "item" && typeof record.item === "string") codes.add(record.item);
    if (record.type === "itemgrid" && Array.isArray(record.rows)) {
      for (const row of record.rows) {
        if (!Array.isArray(row)) continue;
        for (const code of row) if (typeof code === "string" && code !== "") codes.add(code);
      }
    }
    for (const value of Object.values(record)) visit(value);
  };
  visit(layouts);
  return codes;
}
