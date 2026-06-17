"use client";

import { useEffect, useState } from "react";

export type Viewport = { width: number; height: number };

/**
 * Live viewport size, so overlays scale to fill whatever dimensions the operator gave the OBS browser
 * source. SSR-safe defaults (a common 1080p source); the first real measurement is taken in a
 * `requestAnimationFrame` (never synchronously in the effect body) to keep render pure, then updated on
 * resize.
 */
export function useViewport(): Viewport {
  const [size, setSize] = useState<Viewport>({ width: 1920, height: 1080 });

  useEffect(() => {
    const update = () => setSize({ width: window.innerWidth, height: window.innerHeight });
    const raf = requestAnimationFrame(update);
    window.addEventListener("resize", update);
    return () => {
      cancelAnimationFrame(raf);
      window.removeEventListener("resize", update);
    };
  }, []);

  return size;
}
