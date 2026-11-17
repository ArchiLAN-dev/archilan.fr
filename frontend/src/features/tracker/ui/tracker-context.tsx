"use client";

import { createContext, useContext, useEffect, useSyncExternalStore } from "react";

import type { ItemDefinition } from "../engine/items";
import type { EngineAction, LoadedPack } from "../engine/protocol";
import { parseImageMods, type PackImages } from "./pack-images";
import type { TrackerSettings, TrackerView } from "./tracker-view";

/** What every widget of the tracker screen reads (story 54.5). */
export type TrackerScreenContext = {
  pack: LoadedPack;
  view: TrackerView;
  itemsByCode: Map<string, ItemDefinition>;
  itemsById: Map<number, ItemDefinition>;
  settings: TrackerSettings;
  images: PackImages;
  act: (action: EngineAction) => void;
  /** A widget type the screen does not draw (`canvas`, `recentpins`...): logged once. */
  reportUnsupported: (type: string) => void;
};

const Context = createContext<TrackerScreenContext | null>(null);

export const TrackerContextProvider = Context.Provider;

export function useTracker(): TrackerScreenContext {
  const context = useContext(Context);
  if (context === null) throw new Error("useTracker outside of the tracker screen");
  return context;
}

/** The object URL of a pack image, `undefined` while it loads, `null` when the pack lacks it. */
export function usePackImage(images: PackImages, path: string | null): string | null | undefined {
  useEffect(() => {
    if (path !== null) images.request(path);
  }, [images, path]);
  return useSyncExternalStore(
    images.subscribe,
    () => (path === null ? null : images.get(path)),
    () => undefined,
  );
}

type PackImageProps = {
  path: string | null;
  mods?: string | null;
  /** Greys the image on top of its mods: an inactive item without a dedicated image. */
  disabled?: boolean;
  alt: string;
  className?: string;
};

/** A pack image with its PopTracker modifiers (`@disabled`, `overlay|...`). */
export function PackImage({ path, mods = null, disabled = false, alt, className = "" }: PackImageProps) {
  const { images } = useTracker();
  const url = usePackImage(images, path);
  const parsed = parseImageMods(mods);
  const grey = disabled || parsed.disabled;
  if (url === null || url === undefined) {
    return <span aria-label={alt} className={`block ${className}`} role="img" />;
  }
  return (
    <span className={`relative block ${className}`}>
      {/* eslint-disable-next-line @next/next/no-img-element -- object URL of a pack file, nothing to optimise */}
      <img
        alt={alt}
        className={`size-full object-contain [image-rendering:pixelated] ${grey ? "brightness-50 grayscale" : ""}`}
        draggable={false}
        src={url}
      />
      {parsed.overlays.map((overlay) => (
        <Overlay key={overlay} path={overlay} />
      ))}
    </span>
  );
}

function Overlay({ path }: { path: string }) {
  const { images } = useTracker();
  const url = usePackImage(images, path);
  if (url === null || url === undefined) return null;
  // eslint-disable-next-line @next/next/no-img-element -- see above
  return <img alt="" className="absolute inset-0 size-full object-contain" draggable={false} src={url} />;
}
