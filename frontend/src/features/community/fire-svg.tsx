"use client";

import { useId, useSyncExternalStore } from "react";
import styles from "./avatar-frame.module.css";

const QUERY = "(prefers-reduced-motion: reduce)";

function usePrefersReducedMotion(): boolean {
  return useSyncExternalStore(
    (onChange) => {
      const mq = window.matchMedia(QUERY);
      mq.addEventListener("change", onChange);
      return () => mq.removeEventListener("change", onChange);
    },
    () => window.matchMedia(QUERY).matches,
    () => false,
  );
}

/**
 * Organic fire ring rendered with an SVG turbulence + displacement filter: a fiery rounded-rect stroke is
 * warped by animated fractal noise (flicker) that scrolls upward (rising flames). Far more lifelike than
 * stacked DOM flames, and still vector/dependency-free. Frozen under prefers-reduced-motion.
 */
export function FireSvg() {
  const reduced = usePrefersReducedMotion();
  const id = useId().replace(/:/g, "");

  return (
    <svg aria-hidden className={styles.fireSvg} preserveAspectRatio="none" viewBox="0 0 120 120">
      <defs>
        <linearGradient gradientUnits="userSpaceOnUse" id={`${id}g`} x1="0" x2="0" y1="120" y2="0">
          <stop offset="0%" stopColor="#fff7ad" />
          <stop offset="28%" stopColor="#fde047" />
          <stop offset="52%" stopColor="#fb923c" />
          <stop offset="78%" stopColor="#ef4444" />
          <stop offset="100%" stopColor="#ef4444" stopOpacity="0" />
        </linearGradient>
        {/* The rect bbox is 100×100 and the filter region is 200% tall (= 200 user units); with
            baseFrequencyY=0.05 the stitched noise tiles exactly 10× → period 20, so offsetting dy by -20
            loops with no visible seam. */}
        <filter height="200%" id={`${id}f`} width="180%" x="-40%" y="-50%">
          <feTurbulence
            baseFrequency="0.015 0.05"
            numOctaves="3"
            result="noise"
            seed="6"
            stitchTiles="stitch"
            type="fractalNoise"
          />
          <feOffset dy="0" in="noise" result="scrolled">
            {reduced ? null : (
              <animate attributeName="dy" calcMode="linear" dur="2.6s" repeatCount="indefinite" values="0;-20" />
            )}
          </feOffset>
          <feDisplacementMap in="SourceGraphic" in2="scrolled" result="disp" scale="20" xChannelSelector="R" yChannelSelector="G">
            {reduced ? null : (
              <animate attributeName="scale" dur="2.2s" repeatCount="indefinite" values="17;26;17" />
            )}
          </feDisplacementMap>
          <feGaussianBlur stdDeviation="0.6" />
        </filter>
      </defs>
      <rect
        fill="none"
        filter={`url(#${id}f)`}
        height="100"
        rx="20"
        ry="20"
        stroke={`url(#${id}g)`}
        strokeWidth="13"
        width="100"
        x="10"
        y="10"
      />
    </svg>
  );
}
