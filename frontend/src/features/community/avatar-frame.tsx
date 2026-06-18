import type { CSSProperties, ReactNode } from "react";
import { getAvatarFrame } from "./avatar-frames";
import { FireSvg } from "./fire-svg";
import { LottieFrame } from "./lottie-frame";
import styles from "./avatar-frame.module.css";

const PLAIN = "overflow-hidden rounded-2xl border-4 border-surface ring-1 ring-border bg-surface";

// Glowing motes that drift up the frame and fade (embers / spectral / fire). `from` is the start height.
const PARTICLES: { left: string; from: string; size: number; d: number; delay: number }[] = [
  { left: "14%", from: "2%", size: 4, d: 3.2, delay: 0 },
  { left: "26%", from: "-4%", size: 3, d: 3.8, delay: 0.9 },
  { left: "38%", from: "6%", size: 5, d: 2.9, delay: 1.7 },
  { left: "50%", from: "0%", size: 3, d: 3.5, delay: 0.5 },
  { left: "62%", from: "-3%", size: 4, d: 3.1, delay: 2.2 },
  { left: "74%", from: "4%", size: 3, d: 3.9, delay: 1.2 },
  { left: "86%", from: "1%", size: 4, d: 3.3, delay: 0.3 },
  { left: "8%", from: "-2%", size: 3, d: 4.1, delay: 2.6 },
  { left: "20%", from: "8%", size: 4, d: 2.7, delay: 1.0 },
  { left: "44%", from: "-5%", size: 3, d: 3.6, delay: 0.2 },
  { left: "56%", from: "5%", size: 5, d: 3.0, delay: 1.9 },
  { left: "68%", from: "-1%", size: 3, d: 3.7, delay: 0.7 },
  { left: "80%", from: "7%", size: 4, d: 2.8, delay: 2.4 },
  { left: "92%", from: "-3%", size: 3, d: 4.0, delay: 1.5 },
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
  animated = true,
}: {
  frameKey: string | null;
  className?: string;
  children: ReactNode;
  /** false (e.g. editor swatches) renders the lightweight CSS/SVG preview instead of the live Lottie. */
  animated?: boolean;
}) {
  const frame = getAvatarFrame(frameKey);
  const size = className ?? "";

  if (!frame) {
    return <div className={`${PLAIN} ${size}`}>{children}</div>;
  }

  // A designer-made Lottie overlay (only when animated — never 16× in the picker).
  if (frame.lottie && animated) {
    return (
      <div className={`${styles.frame} ${styles.lottieFrame} ${size}`}>
        <div className={styles.inner}>{children}</div>
        <LottieFrame className={styles.lottieOverlay} src={frame.lottie} />
      </div>
    );
  }

  const style = frame.color ? ({ "--c1": frame.color } as CSSProperties) : undefined;
  const hasParticles = frame.variant === "fire" || frame.variant === "embers" || frame.variant === "spectral";

  return (
    <div className={`${styles.frame} ${styles[frame.variant]} ${size}`} style={style}>
      {frame.variant === "fire" ? <FireSvg /> : null}
      {hasParticles ? (
        <span aria-hidden className={styles.particleLayer}>
          {PARTICLES.map((p, i) => (
            <i
              className={styles.particle}
              key={i}
              style={
                {
                  left: p.left,
                  "--from": p.from,
                  width: p.size,
                  height: p.size,
                  animationDuration: `${p.d}s`,
                  animationDelay: `${p.delay}s`,
                } as CSSProperties
              }
            />
          ))}
        </span>
      ) : null}
      <div className={styles.inner}>{children}</div>
    </div>
  );
}
