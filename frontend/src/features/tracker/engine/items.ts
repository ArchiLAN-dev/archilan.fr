import { isJsonObject, readBoolean, readNumber, readObjects, readString, splitCodes, type JsonObject } from "./json-read";

/**
 * The items of a pack (story 54.4), as the PopTracker spec describes them: `doc/PACKS.md`, section "Items".
 */
export const ITEM_TYPES = [
  "toggle",
  "progressive",
  "consumable",
  "progressive_toggle",
  "composite_toggle",
  "toggle_badged",
  "static",
] as const;

export type ItemType = (typeof ITEM_TYPES)[number];

export type MouseButton = "left" | "right";

export type ItemImage = {
  img: string | null;
  imgMods: string;
  disabledImg: string | null;
  disabledImgMods: string | null;
};

/** One stage of an item; `codes` already includes what `inherit_codes` brings. */
export type ItemStage = ItemImage & { name: string | null; codes: string[] };

/** The four images of a `composite_toggle`, by the state of its left and right items. */
export type CompositeImage = ItemImage & { left: boolean; right: boolean };

/** What the interface needs to draw an item, fixed for the whole session. */
export type ItemDefinition = {
  id: number;
  name: string;
  type: ItemType;
  stages: ItemStage[];
  /** `consumable` */
  minQuantity: number;
  maxQuantity: number;
  /** `toggle_badged`: id of the item drawn under this one. */
  baseItemId: number | null;
  /** `composite_toggle` */
  leftItemId: number | null;
  rightItemId: number | null;
  compositeImages: CompositeImage[];
  overlayBackground: string | null;
  overlayFontSize: number | null;
  /**
   * Story 54.8: `progressive` codes set on the item itself, next to its stages. PopTracker provides them in every
   * stage while the item is active (Inscryption keeps its options this way).
   */
  itemCodes?: string[];
};

/** What changes during a session. */
export type ItemState = {
  active: boolean;
  stage: number;
  count: number;
  overlay: string;
  /** Story 54.8: a Lua item draws the icon and name its Lua table holds now. */
  icon?: { img: string; imgMods: string };
  name?: string;
};

export class TrackerItem {
  readonly definition: ItemDefinition;
  active: boolean;
  stage: number;
  count: number;
  overlay = "";
  private iconOverride: { icon: { img: string; imgMods: string }; until: string } | null = null;
  increment: number;
  decrement: number;
  minCount: number;
  maxCount: number;
  readonly allowDisabled: boolean;
  readonly loop: boolean;
  /** Every code the item may provide, whatever its state. */
  private readonly allCodes: ReadonlySet<string>;

  constructor(definition: ItemDefinition, options: ItemOptions) {
    this.definition = definition;
    this.allowDisabled = options.allowDisabled;
    this.loop = options.loop;
    this.increment = options.increment;
    this.decrement = options.decrement;
    this.minCount = definition.minQuantity;
    this.maxCount = definition.maxQuantity;
    this.active = options.initialActive;
    this.stage = clamp(options.initialStage, 0, Math.max(0, definition.stages.length - 1));
    this.count = options.initialCount;
    this.allCodes = new Set([...definition.stages.flatMap((stage) => stage.codes), ...(definition.itemCodes ?? [])]);
  }

  get type(): ItemType {
    return this.definition.type;
  }

  /** Every code the item can provide, in any stage. */
  codes(): string[] {
    return [...this.allCodes];
  }

  canProvide(code: string): boolean {
    return this.allCodes.has(code);
  }

  /** How much of `code` the item gives now: the quantity for a consumable, else 0 or 1. */
  providedCount(code: string, lookup: (id: number) => TrackerItem | undefined): number {
    if (!this.canProvide(code)) return 0;
    switch (this.type) {
      case "consumable":
        return this.count;
      case "static":
        return 1;
      case "toggle":
      case "toggle_badged":
        return this.active ? 1 : 0;
      case "composite_toggle": {
        const left = this.definition.leftItemId === null ? undefined : lookup(this.definition.leftItemId);
        const right = this.definition.rightItemId === null ? undefined : lookup(this.definition.rightItemId);
        // Story 54.11, as PopTracker: one per part that is on (0, 1 or 2); the parts' own codes are not repeated.
        return (left?.active === true ? 1 : 0) + (right?.active === true ? 1 : 0);
      }
      case "progressive":
      case "progressive_toggle": {
        if (!this.active) return 0;
        if (this.definition.itemCodes?.includes(code) === true) return 1;
        return this.definition.stages[this.stage]?.codes.includes(code) === true ? 1 : 0;
      }
    }
  }

  /** A click from the interface; returns the items it changed (a composite toggle changes its linked items). */
  click(button: MouseButton, lookup: (id: number) => TrackerItem | undefined): TrackerItem[] {
    switch (this.type) {
      case "static":
        return [];
      case "toggle":
        this.active = !this.active;
        return [this];
      case "consumable":
        this.setCount(button === "left" ? this.count + this.increment : this.count - this.decrement);
        return [this];
      case "progressive":
        this.clickProgressive(button);
        return [this];
      case "progressive_toggle":
        if (button === "left") this.active = !this.active;
        else this.stepStage(1);
        return [this];
      case "toggle_badged": {
        if (button === "right") {
          this.active = !this.active;
          return [this];
        }
        const base = this.definition.baseItemId === null ? undefined : lookup(this.definition.baseItemId);
        return base === undefined ? [] : [...base.click("left", lookup), this];
      }
      case "composite_toggle": {
        const linkedId = button === "left" ? this.definition.leftItemId : this.definition.rightItemId;
        const linked = linkedId === null ? undefined : lookup(linkedId);
        if (linked === undefined) return [];
        linked.active = !linked.active;
        return [linked, this];
      }
    }
  }

  setCount(count: number): void {
    const upper = this.maxCount < this.minCount ? Number.POSITIVE_INFINITY : this.maxCount;
    this.count = clamp(Math.trunc(count), this.minCount, upper);
  }

  setStage(stage: number): void {
    this.stage = clamp(Math.trunc(stage), 0, Math.max(0, this.definition.stages.length - 1));
    if (this.type === "progressive" && !this.allowDisabled) this.active = true;
  }

  /** `.Active` from Lua: a consumable reads as active when it has a quantity and is set by it. */
  isActive(): boolean {
    if (this.type === "consumable") return this.count > 0;
    if (this.type === "static") return true;
    return this.active;
  }

  setActive(active: boolean): void {
    if (this.type === "static") return;
    if (this.type === "consumable") {
      this.setCount(active ? Math.max(this.count, 1) : 0);
      return;
    }
    if (this.type === "progressive" && !this.allowDisabled) return;
    this.active = active;
  }

  state(): ItemState {
    const state: ItemState = { active: this.isActive(), stage: this.stage, count: this.count, overlay: this.overlay };
    if (this.iconOverride !== null && this.iconOverride.until === this.stateKey()) state.icon = this.iconOverride.icon;
    return state;
  }

  /**
   * Story 54.11: `.Icon` set from Lua, as PopTracker: drawn instead of the stage's image until the item's state
   * changes. `null` drops it.
   */
  overrideIcon(icon: { img: string; imgMods: string } | null): void {
    this.iconOverride = icon === null ? null : { icon, until: this.stateKey() };
  }

  private stateKey(): string {
    return `${String(this.isActive())}:${this.stage}:${this.count}`;
  }

  private clickProgressive(button: MouseButton): void {
    if (button === "left") {
      if (!this.active) {
        this.active = true;
        return;
      }
      this.stepStage(1);
      return;
    }
    // Story 54.11, as PopTracker: with allow_disabled, "off" is a stage before the first one; going back from it loops
    // to the last stage, switched on.
    if (this.allowDisabled && this.active && this.stage === 0) {
      this.active = false;
      return;
    }
    if (this.stage > 0) {
      this.stage -= 1;
      return;
    }
    if (!this.loop || this.definition.stages.length === 0) return;
    this.stage = this.definition.stages.length - 1;
    this.active = true;
  }

  private stepStage(direction: 1 | -1): void {
    const last = this.definition.stages.length - 1;
    const next = this.stage + direction;
    if (next > last) {
      if (!this.loop) return;
      if (this.type === "progressive" && this.allowDisabled) {
        this.active = false;
        this.stage = 0;
        return;
      }
      this.stage = 0;
      return;
    }
    this.stage = Math.max(0, next);
  }
}

type ItemOptions = {
  allowDisabled: boolean;
  loop: boolean;
  increment: number;
  decrement: number;
  initialActive: boolean;
  initialStage: number;
  initialCount: number;
};

function clamp(value: number, min: number, max: number): number {
  return Math.min(Math.max(value, min), max);
}

function isItemType(value: string | null): value is ItemType {
  return value !== null && (ITEM_TYPES as readonly string[]).includes(value);
}

function readImage(source: JsonObject): ItemImage {
  return {
    img: readString(source, "img"),
    imgMods: readString(source, "img_mods") ?? "",
    disabledImg: readString(source, "disabled_img"),
    disabledImgMods: readString(source, "disabled_img_mods"),
  };
}

/** Stages with their codes resolved: `inherit_codes` (default true) adds the codes of the stage before. */
function readStages(source: JsonObject): ItemStage[] {
  const stages: ItemStage[] = [];
  let previous: string[] = [];
  for (const raw of readObjects(source, "stages")) {
    const own = splitCodes(readString(raw, "codes"));
    const inherit = readBoolean(raw, "inherit_codes") ?? true;
    const codes = inherit ? [...new Set([...previous, ...own])] : own;
    stages.push({ ...readImage(raw), name: readString(raw, "name"), codes });
    previous = codes;
  }
  return stages;
}

export type ItemParseIssue = { message: string };

/**
 * Items of one `Tracker:AddItems` file. `nextId` numbers them across files; `findByCode` resolves the items a
 * `toggle_badged` or a `composite_toggle` points to, which must be defined before.
 */
export function parseItems(
  json: unknown,
  nextId: () => number,
  findByCode: (code: string) => TrackerItem | undefined,
  issues: ItemParseIssue[],
): TrackerItem[] {
  if (!Array.isArray(json)) {
    issues.push({ message: "Le fichier d'items n'est pas une liste." });
    return [];
  }
  const items: TrackerItem[] = [];
  for (const raw of json) {
    if (!isJsonObject(raw)) continue;
    const type = readString(raw, "type");
    const name = readString(raw, "name") ?? "";
    if (!isItemType(type)) {
      issues.push({ message: `Type d'item inconnu « ${String(type)} » (${name}).` });
      continue;
    }
    const item = parseItem(raw, type, name, nextId(), (code) => findByCode(code) ?? items.find((i) => i.canProvide(code)));
    items.push(item);
  }
  return items;
}

function parseItem(
  raw: JsonObject,
  type: ItemType,
  name: string,
  id: number,
  findByCode: (code: string) => TrackerItem | undefined,
): TrackerItem {
  const ownCodes = splitCodes(readString(raw, "codes"));
  const stagesFromJson = readStages(raw);
  const stages: ItemStage[] =
    (type === "progressive" || type === "progressive_toggle") && stagesFromJson.length > 0
      ? stagesFromJson
      : [{ ...readImage(raw), name: null, codes: ownCodes }];
  if (type === "composite_toggle" && ownCodes.length > 0) stages[0] = { ...stages[0], codes: ownCodes };
  const itemCodes = stages === stagesFromJson ? ownCodes.filter((code) => !stages.some((stage) => stage.codes.includes(code))) : [];

  const minQuantity = readNumber(raw, "min_quantity") ?? 0;
  // Story 54.11, as PopTracker: no max_quantity means no ceiling (a max below the min is read as infinite).
  const maxQuantity = readNumber(raw, "max_quantity") ?? Math.min(-1, minQuantity - 1);
  const baseCode = readString(raw, "base_item");
  const leftCode = readString(raw, "item_left");
  const rightCode = readString(raw, "item_right");

  const definition: ItemDefinition = {
    id,
    name,
    type,
    stages,
    minQuantity,
    maxQuantity,
    baseItemId: baseCode === null ? null : (findByCode(baseCode)?.definition.id ?? null),
    leftItemId: leftCode === null ? null : (findByCode(leftCode)?.definition.id ?? null),
    rightItemId: rightCode === null ? null : (findByCode(rightCode)?.definition.id ?? null),
    compositeImages: readObjects(raw, "images").map((image) => ({
      ...readImage(image),
      left: readBoolean(image, "left") ?? false,
      right: readBoolean(image, "right") ?? false,
    })),
    overlayBackground: readString(raw, "overlay_background"),
    ...(itemCodes.length > 0 ? { itemCodes } : {}),
    overlayFontSize: readNumber(raw, "overlay_font_size") ?? readNumber(raw, "badge_font_size"),
  };

  const allowDisabled = type === "progressive" ? (readBoolean(raw, "allow_disabled") ?? true) : false;
  const initialActive =
    type === "static" || (type === "progressive" && !allowDisabled) || (readBoolean(raw, "initial_active_state") ?? false);
  const increment = readNumber(raw, "increment") ?? 1;
  const item = new TrackerItem(definition, {
    allowDisabled,
    // Story 54.11, as PopTracker: a progressive_toggle always loops; decrement defaults to the increment.
    loop: type === "progressive_toggle" || (readBoolean(raw, "loop") ?? false),
    increment,
    decrement: readNumber(raw, "decrement") ?? increment,
    initialActive,
    initialStage: readNumber(raw, "initial_stage_idx") ?? 0,
    initialCount: 0,
  });
  if (type === "consumable") item.setCount(readNumber(raw, "initial_quantity") ?? minQuantity);
  return item;
}
