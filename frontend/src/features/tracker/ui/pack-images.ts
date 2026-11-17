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

export type ImageMods = { disabled: boolean; overlays: string[] };

/**
 * PopTracker image modifiers, comma separated: `@disabled` greys the image, `overlay|path` draws another image on
 * top. `@enabled`, `none` and the rest change nothing here.
 */
export function parseImageMods(mods: string | null): ImageMods {
  const result: ImageMods = { disabled: false, overlays: [] };
  if (mods === null) return result;
  for (const raw of mods.split(",")) {
    const mod = raw.trim();
    if (mod === "@disabled") result.disabled = true;
    else if (mod.startsWith("overlay|")) {
      const path = mod.slice("overlay|".length).trim();
      if (path !== "") result.overlays.push(path);
    }
  }
  return result;
}
