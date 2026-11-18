/**
 * The pack's images, read from the Worker once each and kept as object URLs for the life of the screen
 * (story 54.5). React reads them through `useSyncExternalStore`, so a component re-renders when its image arrives.
 */
export class PackImages {
  /** `null`: the pack has no such file. */
  private readonly urls = new Map<string, string | null>();
  private readonly pending = new Set<string>();
  private readonly listeners = new Set<() => void>();
  private disposed = false;

  constructor(
    private readonly load: (path: string) => Promise<Blob | null>,
    private readonly createUrl: (blob: Blob) => string = (blob) => URL.createObjectURL(blob),
    private readonly revokeUrl: (url: string) => void = (url) => URL.revokeObjectURL(url),
  ) {}

  /** `undefined` while the image has not been read. */
  get(path: string): string | null | undefined {
    return this.urls.get(path);
  }

  request(path: string): void {
    if (this.disposed || this.urls.has(path) || this.pending.has(path)) return;
    this.pending.add(path);
    this.load(path)
      .catch(() => null)
      .then((blob) => {
        this.pending.delete(path);
        if (this.disposed) return;
        this.urls.set(path, blob === null ? null : this.createUrl(blob));
        for (const listener of this.listeners) listener();
      });
  }

  subscribe = (listener: () => void): (() => void) => {
    this.listeners.add(listener);
    return () => this.listeners.delete(listener);
  };

  dispose(): void {
    this.disposed = true;
    for (const url of this.urls.values()) if (url !== null) this.revokeUrl(url);
    this.urls.clear();
    this.listeners.clear();
  }
}

/** Story 54.11: PopTracker's grey, the default meaning of `@disabled`: 0 % saturation and 67 % brightness. */
export const GREY_FILTER = "grayscale(1) brightness(0.67)";

/** One step of an image's modifiers: a filter on what is drawn so far, or an image drawn over it with its own filters. */
export type ImageModStep = { kind: "filter"; css: string } | { kind: "overlay"; path: string; css: string };

/**
 * PopTracker image modifiers (doc/PACKS.md, img_mods), comma separated and applied left to right (story 54.11):
 * `@disabled`, `grey` / `gray` / `disable`, `dim`, `brightness|x`, `saturation|x|colorspace`, `greyscale` /
 * `grayscale`, and `overlay|path|filters...` whose filters apply to the overlay only. `@enabled`, `none` and unknown
 * modifiers change nothing.
 */
export function parseImageMods(mods: string | null): ImageModStep[] {
  const steps: ImageModStep[] = [];
  if (mods === null) return steps;
  for (const raw of mods.split(",")) {
    const [name = "", ...args] = raw.trim().split("|").map((part) => part.trim());
    if (name === "overlay") {
      const [path = "", ...filters] = args;
      if (path !== "") steps.push({ kind: "overlay", path, css: filters.map((filter) => filterCss(filter, [])).filter(Boolean).join(" ") });
      continue;
    }
    const css = filterCss(name, args);
    if (css !== "") steps.push({ kind: "filter", css });
  }
  return steps;
}

function filterCss(name: string, args: string[]): string {
  switch (name) {
    case "@disabled":
    case "grey":
    case "gray":
    case "disable":
      return GREY_FILTER;
    case "dim":
      return "brightness(0.5)";
    case "greyscale":
    case "grayscale":
      return "grayscale(1)";
    case "brightness":
    case "saturation": {
      const value = Number(args[0]);
      if (!Number.isFinite(value) || value < 0) return "";
      return name === "brightness" ? `brightness(${value})` : `saturate(${value})`;
    }
    default:
      return "";
  }
}
