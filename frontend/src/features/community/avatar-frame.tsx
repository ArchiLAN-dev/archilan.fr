import type { CSSProperties, ReactNode } from "react";
import { getAvatarFrame } from "./avatar-frames";
import styles from "./avatar-frame.module.css";

const PLAIN = "overflow-hidden rounded-2xl border-4 border-surface ring-1 ring-border bg-surface";

// Flame tongues for the "fire" frame: clustered along the top (rising above the avatar) and down the sides,
// so the fire wraps the frame and licks upward. Sizes are % of the avatar so they scale with any size.
const FIRE_FLAMES: { left: string; bottom: string; w: string; h: string; d: number; delay: number }[] = [
  { left: "6%", bottom: "76%", w: "11%", h: "26%", d: 0.8, delay: 0 },
  { left: "19%", bottom: "84%", w: "13%", h: "34%", d: 0.95, delay: 0.2 },
  { left: "32%", bottom: "79%", w: "12%", h: "29%", d: 0.7, delay: 0.4 },
  { left: "45%", bottom: "87%", w: "14%", h: "37%", d: 1.05, delay: 0.1 },
  { left: "58%", bottom: "80%", w: "12%", h: "30%", d: 0.78, delay: 0.5 },
  { left: "71%", bottom: "85%", w: "13%", h: "33%", d: 0.9, delay: 0.3 },
  { left: "84%", bottom: "76%", w: "11%", h: "25%", d: 0.72, delay: 0.15 },
  { left: "-3%", bottom: "28%", w: "10%", h: "22%", d: 0.88, delay: 0.25 },
  { left: "-4%", bottom: "52%", w: "11%", h: "26%", d: 1.0, delay: 0.45 },
  { left: "-1%", bottom: "70%", w: "10%", h: "24%", d: 0.82, delay: 0.1 },
  { left: "93%", bottom: "28%", w: "10%", h: "22%", d: 0.9, delay: 0.35 },
  { left: "94%", bottom: "52%", w: "11%", h: "26%", d: 0.76, delay: 0.05 },
  { left: "91%", bottom: "70%", w: "10%", h: "24%", d: 0.94, delay: 0.5 },
];

/**
 * Wraps avatar content in a decorative frame (solid colour, neon glow, or animated effect). With no frame
 * key it renders the plain bordered ring. Pass the size via `className` (e.g. "size-24 sm:size-28"). Motion
 * is disabled under prefers-reduced-motion (handled in CSS).
 */
export function AvatarFrame({
  frameKey,
  className,
  children,
}: {
  frameKey: string | null;
  className?: string;
  children: ReactNode;
}) {
  const frame = getAvatarFrame(frameKey);
  const size = className ?? "";

  if (!frame) {
    return <div className={`${PLAIN} ${size}`}>{children}</div>;
  }

  const style = frame.color ? ({ "--c1": frame.color } as CSSProperties) : undefined;

  return (
    <div className={`${styles.frame} ${styles[frame.variant]} ${size}`} style={style}>
      {frame.variant === "fire" ? (
        <span aria-hidden className={styles.fireLayer}>
          {FIRE_FLAMES.map((f, i) => (
            <i
              className={styles.flame}
              key={i}
              style={{
                left: f.left,
                bottom: f.bottom,
                width: f.w,
                height: f.h,
                animationDuration: `${f.d}s`,
                animationDelay: `${f.delay}s`,
              }}
            />
          ))}
        </span>
      ) : null}
      <div className={styles.inner}>{children}</div>
    </div>
  );
}
