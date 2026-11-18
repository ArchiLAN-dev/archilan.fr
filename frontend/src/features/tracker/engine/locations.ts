import { isJsonObject, readBoolean, readNumber, readObjects, readString, splitCodes, type JsonObject } from "./json-read";
import { Highlight } from "./levels";
import { parseRules, type RuleEntry } from "./rules";

/**
 * Locations and sections of a pack (story 54.4), `doc/PACKS.md` section "Locations". A location is named by its
 * path from the root (`Snowhead/Goron Village`); a reference may also use the bare name (`Goron Village`).
 */
export type MapLocation = {
  map: string;
  x: number;
  y: number;
  size: number | null;
  borderThickness: number | null;
  shape: string | null;
  restrictVisibilityRules: RuleEntry[];
  forceInvisibilityRules: RuleEntry[];
};

export type ChestImages = {
  unopened: string | null;
  opened: string | null;
};

export class TrackerSection {
  /** Interface state: how many checks are left. */
  available: number;
  highlight: Highlight = Highlight.None;
  /** Story 54.11: `inspectable_sequence_break`, from the location when the section does not say. */
  inspectableSequenceBreak = false;

  constructor(
    readonly id: number,
    readonly location: TrackerLocation,
    readonly name: string,
    readonly itemCount: number,
    readonly hostedItems: string[],
    readonly clearAsGroup: boolean,
    readonly accessRules: RuleEntry[],
    readonly visibilityRules: RuleEntry[],
    readonly images: ChestImages,
    /** `ref`: the path of the section this one shows, resolved once every file is loaded. */
    readonly ref: string | null,
  ) {
    this.available = itemCount;
  }

  get path(): string {
    return `${this.location.path}/${this.name}`;
  }
}

export class TrackerLocation {
  parent: TrackerLocation | null;
  /** Story 54.11: `inspectable_sequence_break`, from the parent when the location does not say. */
  inspectableSequenceBreak = false;
  readonly children: TrackerLocation[] = [];
  readonly sections: TrackerSection[] = [];

  constructor(
    readonly id: number,
    readonly name: string,
    parent: TrackerLocation | null,
    readonly accessRules: RuleEntry[],
    readonly visibilityRules: RuleEntry[],
    readonly mapLocations: MapLocation[],
    readonly images: ChestImages,
    readonly overlayBackground: string | null,
  ) {
    this.parent = parent;
  }

  get path(): string {
    return this.parent === null ? this.name : `${this.parent.path}/${this.name}`;
  }
}

export type LocationIssue = { message: string };

export class LocationRegistry {
  readonly roots: TrackerLocation[] = [];
  private readonly locationList: TrackerLocation[] = [];
  private readonly sectionList: TrackerSection[] = [];
  private nextId = 1;
  private readonly byPath = new Map<string, TrackerLocation>();
  private readonly byName = new Map<string, TrackerLocation>();
  private readonly sectionsByPath = new Map<string, TrackerSection>();
  private readonly sectionsById = new Map<number, TrackerSection>();

  /** One `Tracker:AddLocations` file. */
  add(json: unknown, issues: LocationIssue[]): void {
    if (!Array.isArray(json)) {
      issues.push({ message: "Le fichier de locations n'est pas une liste." });
      return;
    }
    for (const raw of json) {
      if (!isJsonObject(raw)) continue;
      const parentName = readString(raw, "parent");
      let parent: TrackerLocation | null = null;
      if (parentName !== null) {
        parent = this.findLocation(parentName) ?? null;
        if (parent === null) issues.push({ message: `Parent introuvable « ${parentName} ».` });
      }
      this.addLocation(raw, parent, issues);
    }
  }

  /** In file order; several locations may share a path (MM has unnamed children). */
  allLocations(): readonly TrackerLocation[] {
    return this.locationList;
  }

  allSections(): readonly TrackerSection[] {
    return this.sectionList;
  }

  sectionById(id: number): TrackerSection | undefined {
    return this.sectionsById.get(id);
  }

  /** `Snowhead/Goron Village` by its full path, else by its bare name, else by the end of its path. */
  findLocation(reference: string): TrackerLocation | undefined {
    const exact = this.byPath.get(reference) ?? this.byName.get(reference);
    if (exact !== undefined) return exact;
    const suffix = `/${reference}`;
    for (const [path, location] of this.byPath) {
      if (path.endsWith(suffix)) return location;
    }
    return undefined;
  }

  /** `Location/Section`; the location part follows {@link findLocation}. */
  findSection(reference: string): TrackerSection | undefined {
    const direct = this.sectionsByPath.get(reference);
    if (direct !== undefined) return direct;
    const slash = reference.lastIndexOf("/");
    if (slash < 0) return undefined;
    const location = this.findLocation(reference.slice(0, slash));
    const name = reference.slice(slash + 1);
    return location?.sections.find((section) => section.name === name);
  }

  /** The section a `ref` section shows, following chains of refs; itself when it is not a ref. */
  resolveRef(section: TrackerSection): TrackerSection | undefined {
    let current: TrackerSection | undefined = section;
    const seen = new Set<TrackerSection>();
    while (current?.ref != null) {
      if (seen.has(current)) return undefined;
      seen.add(current);
      current = this.findSection(current.ref);
    }
    return current;
  }

  private addLocation(raw: JsonObject, parent: TrackerLocation | null, issues: LocationIssue[]): void {
    const name = readString(raw, "name") ?? "";
    const parentImages = parent?.images ?? { unopened: null, opened: null };
    const location = new TrackerLocation(
      this.nextId++,
      name,
      parent,
      parseRules(raw.access_rules),
      parseRules(raw.visibility_rules),
      readObjects(raw, "map_locations").flatMap((map) => readMapLocation(map)),
      {
        unopened: readString(raw, "chest_unopened_img") ?? parentImages.unopened,
        opened: readString(raw, "chest_opened_img") ?? parentImages.opened,
      },
      readString(raw, "overlay_background") ?? parent?.overlayBackground ?? null,
    );
    location.inspectableSequenceBreak = readBoolean(raw, "inspectable_sequence_break") ?? parent?.inspectableSequenceBreak ?? false;
    if (parent === null) this.roots.push(location);
    else parent.children.push(location);
    this.locationList.push(location);
    const path = location.path;
    if (this.byPath.has(path)) {
      if (name !== "") issues.push({ message: `Location en double « ${path} ».` });
    } else {
      this.byPath.set(path, location);
    }
    if (!this.byName.has(name)) this.byName.set(name, location);

    for (const section of readObjects(raw, "sections")) {
      const parsed = readSection(this.nextId++, section, location);
      parsed.inspectableSequenceBreak = readBoolean(section, "inspectable_sequence_break") ?? location.inspectableSequenceBreak;
      location.sections.push(parsed);
      this.sectionList.push(parsed);
      this.sectionsById.set(parsed.id, parsed);
      if (!this.sectionsByPath.has(parsed.path)) this.sectionsByPath.set(parsed.path, parsed);
    }
    for (const child of readObjects(raw, "children")) this.addLocation(child, location, issues);
  }
}

function readMapLocation(raw: JsonObject): MapLocation[] {
  const map = readString(raw, "map");
  const x = readNumber(raw, "x");
  const y = readNumber(raw, "y");
  if (map === null || x === null || y === null) return [];
  return [
    {
      map,
      x,
      y,
      size: readNumber(raw, "size"),
      borderThickness: readNumber(raw, "border_thickness"),
      shape: readString(raw, "shape"),
      restrictVisibilityRules: parseRules(raw.restrict_visibility_rules),
      forceInvisibilityRules: parseRules(raw.force_invisibility_rules),
    },
  ];
}

function readSection(id: number, raw: JsonObject, location: TrackerLocation): TrackerSection {
  const ref = readString(raw, "ref");
  const hostedItems = splitCodes(readString(raw, "hosted_item"));
  const itemCount = readNumber(raw, "item_count") ?? (hostedItems.length > 0 ? 0 : 1);
  return new TrackerSection(
    id,
    location,
    readString(raw, "name") ?? (ref === null ? "" : (ref.split("/").pop() ?? "")),
    ref === null ? Math.max(0, Math.trunc(itemCount)) : 0,
    ref === null ? hostedItems : [],
    readBoolean(raw, "clear_as_group") ?? true,
    parseRules(raw.access_rules),
    parseRules(raw.visibility_rules),
    {
      unopened: readString(raw, "chest_unopened_img") ?? location.images.unopened,
      opened: readString(raw, "chest_opened_img") ?? location.images.opened,
    },
    ref,
  );
}
