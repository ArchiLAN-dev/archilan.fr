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
        <filter height="200%" id={`${id}f`} width="180%" x="-40%" y="-55%">
          <feTurbulence baseFrequency="0.02 0.05" numOctaves="2" result="noise" seed="6" type="fractalNoise">
            {reduced ? null : (
              <animate
                attributeName="baseFrequency"
                dur="5s"
                repeatCount="indefinite"
                values="0.02 0.05;0.024 0.07;0.02 0.05"
              />
            )}
          </feTurbulence>
          <feOffset dy="0" in="noise" result="scrolled">
            {reduced ? null : (
              <animate attributeName="dy" dur="1.4s" repeatCount="indefinite" values="0;-42" />
            )}
          </feOffset>
          <feDisplacementMap in="SourceGraphic" in2="scrolled" scale="22" xChannelSelector="R" yChannelSelector="G" />
          <feGaussianBlur stdDeviation="0.7" />
        </filter>
      </defs>
      <rect
        fill="none"
        filter={`url(#${id}f)`}
        height="92"
        rx="18"
        ry="18"
        stroke={`url(#${id}g)`}
        strokeWidth="13"
        width="92"
        x="14"
        y="14"
      />
    </svg>
  );
}
