import { LuaFactory, type LuaEngine } from "wasmoon";

import { PackError } from "./errors";
import { parseItems, type ItemParseIssue, type TrackerItem } from "./items";
import { isJsonObject, readNumber, readString } from "./json-read";
import { AccessibilityLevel, EMULATED_POPTRACKER_VERSION, Highlight } from "./levels";
import { LocationRegistry, type TrackerLocation, type TrackerSection } from "./locations";
import { LUA_BOOTSTRAP } from "./lua-bootstrap";
import type { PackFiles } from "./pack-files";
import type {
  EngineAction,
  LoadedLocation,
  LoadedMap,
  LoadedPack,
  LogLevel,
  PackManifest,
  SectionState,
  StateUpdate,
  TrackerErrorKind,
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
};

function isLuaApi(value: unknown): value is LuaApi {
  if (!isJsonObject(value)) return false;
  return ["loadScript", "callRule", "itemChanged", "sectionChanged", "archipelagoHandlerCount", "ap", "run"].every(
    (key) => typeof value[key] === "function",
  );
}

type Flag = "BulkUpdate" | "AllowDeferredLogicUpdate";

const MAX_WATCH_ROUNDS = 100;
const MAX_LOGS = 500;

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
  private readonly evaluating = new Set<string>();
  private readonly reported = new Set<string>();
  private readonly pendingItems = new Set<TrackerItem>();
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
    await engine.start();
    return engine;
  }

  pack(): LoadedPack {
    return {
      manifest: this.manifest,
      variantUid: this.variantUid,
      items: this.items.map((item) => item.definition),
      maps: this.maps,
      layouts: this.layouts,
      locations: this.locations.allLocations().map((location) => this.describeLocation(location)),
    };
  }

  /** Everything, and the reference for the next diffs. */
  initialState(): StateUpdate {
    this.previous = this.fullState();
    return this.previous;
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
      throw this.luaFailure(error);
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
      ruleError: (name, message) => this.logOnce(`rule:${String(name)}`, "error", `Règle $${String(name)} : ${String(message)}`),
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
      for (const code of new Set(item.definition.stages.flatMap((stage) => stage.codes))) {
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
        const item = this.requireItem(action.itemId);
        for (const changed of item.click(action.button, (id) => this.itemsById.get(id))) this.markItemChanged(changed);
        return;
      }
      case "itemSet": {
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
    this.levelCache.clear();
    this.callCache.clear();
  }

  private settle(): StateUpdate {
    this.settleWatchers();
    return this.diff();
  }

  /** Watchers run after the change, outside `BulkUpdate`; what they change triggers the next round. */
  private settleWatchers(): void {
    const api = this.luaApi();
    for (let round = 0; round < MAX_WATCH_ROUNDS; round++) {
      if (this.flags.BulkUpdate) return;
      if (this.pendingItems.size === 0 && this.pendingSections.size === 0) return;
      const items = [...this.pendingItems];
      const sections = [...this.pendingSections];
      this.pendingItems.clear();
      this.pendingSections.clear();
      try {
        for (const item of items) {
          api.itemChanged([...new Set(item.definition.stages.flatMap((stage) => stage.codes))].join(","));
        }
        for (const section of sections) api.sectionChanged(this.refFor(section));
      } catch (error) {
        this.log("error", error instanceof Error ? error.message : String(error));
      }
    }
    this.pendingItems.clear();
    this.pendingSections.clear();
    this.log("error", "Les watchers du pack se relancent sans fin : arrêt.");
  }

  // Logic -------------------------------------------------------------------

  private providerCount(code: string): number {
    const items = this.itemsByCode.get(code);
    if (items === undefined) return 0;
    let total = 0;
    for (const item of items) total += item.providedCount(code, (id) => this.itemsById.get(id));
    return total;
  }

  private callFunction(name: string, args: string[]): number | boolean | null {
    const key = `${name}\u0000${args.join("\u0001")}`;
    const cached = this.callCache.get(key);
    if (cached !== undefined) return cached;
    let value: number | boolean | null = null;
    try {
      const result = this.luaApi().callRule(name, ...args);
      if (typeof result === "number" || typeof result === "boolean") value = result;
    } catch (error) {
      this.logOnce(`rule:${name}`, "error", `Règle $${name} : ${error instanceof Error ? error.message : String(error)}`);
    }
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

  /** Memoized, with loop detection: a rule that needs itself is not accessible. */
  private cached(key: string, compute: () => AccessibilityLevel): AccessibilityLevel {
    const hit = this.levelCache.get(key);
    if (hit !== undefined) return hit;
    if (this.evaluating.has(key)) {
      this.logOnce(`loop:${key}`, "error", `Boucle de règles sur ${key.slice(2)}`);
      return AccessibilityLevel.None;
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
    return this.cached(`a:${location.id}`, () => {
      const own = evaluateRules(location.accessRules, this.ruleContext);
      if (location.parent === null || own === AccessibilityLevel.None) return own;
      return Math.min(own, this.locationAccess(location.parent)) as AccessibilityLevel;
    });
  }

  private locationVisible(location: TrackerLocation): boolean {
    const level = this.cached(`v:${location.id}`, () => {
      const own = evaluateRules(location.visibilityRules, this.ruleContext);
      if (location.parent === null || own === AccessibilityLevel.None) return own;
      return this.locationVisible(location.parent) ? own : AccessibilityLevel.None;
    });
    return level !== AccessibilityLevel.None;
  }

  /** Accessibility from the rules only, whether the section is cleared or not. */
  private sectionLogicLevel(section: TrackerSection): AccessibilityLevel {
    const target = this.locations.resolveRef(section);
    if (target === undefined) return AccessibilityLevel.None;
    return this.cached(`s:${target.id}`, () => {
      const own = evaluateRules(target.accessRules, this.ruleContext);
      if (own === AccessibilityLevel.None) return own;
      return Math.min(own, this.locationAccess(target.location)) as AccessibilityLevel;
    });
  }

  private sectionVisible(section: TrackerSection): boolean {
    const target = this.locations.resolveRef(section);
    if (target === undefined) return false;
    const level = this.cached(`w:${target.id}`, () =>
      this.locationVisible(target.location) ? evaluateRules(target.visibilityRules, this.ruleContext) : AccessibilityLevel.None,
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
    const state: StateUpdate = { items: {}, sections: {}, locations: {} };
    for (const item of this.items) state.items[item.definition.id] = item.state();
    for (const section of this.locations.allSections()) {
      if (section.ref !== null) continue;
      state.sections[section.id] = this.sectionState(section);
    }
    for (const location of this.locations.allLocations()) {
      if (location.sections.length > 0) state.locations[location.id] = this.locationLevel(location, true);
    }
    return state;
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

