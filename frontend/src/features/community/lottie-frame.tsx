"use client";

import dynamic from "next/dynamic";
import { useEffect, useState } from "react";
import { usePrefersReducedMotion } from "./use-reduced-motion";

// Code-split the Lottie player so lottie-web only ships when a Lottie frame actually renders.
const Lottie = dynamic(() => import("lottie-react"), { ssr: false });

/**
 * Renders a designer-made Lottie animation as an avatar frame overlay (transparent centre). The JSON is
 * self-hosted under /public/avatar-frames and fetched on mount. Paused (static first frame) under
 * prefers-reduced-motion. Returns nothing if the asset is missing, so the caller can fall back.
 */
export function LottieFrame({ src, className }: { src: string; className?: string }) {
  const reduced = usePrefersReducedMotion();
  const [data, setData] = useState<object | null>(null);

  useEffect(() => {
    let cancelled = false;
    fetch(src)
      .then((res) => (res.ok ? res.json() : null))
      .then((json: unknown) => {
        if (!cancelled && json && typeof json === "object") setData(json as object);
      })
      .catch(() => {});
    return () => {
      cancelled = true;
    };
  }, [src]);

  if (!data) return null;

  return <Lottie animationData={data} autoplay={!reduced} className={className} loop={!reduced} />;
}
