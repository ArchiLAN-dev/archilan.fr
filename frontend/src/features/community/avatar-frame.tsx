import type { CSSProperties, ReactNode } from "react";
import { AvatarFrameVideoLayer } from "./avatar-frame-video";
import { getAvatarFrame } from "./avatar-frames";
import styles from "./avatar-frame.module.css";

const PLAIN = "overflow-hidden rounded-2xl border-4 border-surface ring-1 ring-border bg-surface";

// Glowing motes that drift up the frame and fade (the "spectral" frame). `from` is the start height.
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
 * Wraps avatar content in a decorative frame (solid colour, neon glow, animated effect, or video overlay). With
 * no frame key it renders the plain bordered ring. Pass the size via `className` (e.g. "size-24 sm:size-28").
 * Motion is disabled under prefers-reduced-motion (in CSS, and in the video layer for the video frames).
 * `preview` is the picker swatch: a video frame then shows its still poster inside the swatch, never overflowing.
 */
export function AvatarFrame({
  frameKey,
  className,
  preview = false,
  children,
}: {
  frameKey: string | null;
  className?: string;
  preview?: boolean;
  children: ReactNode;
}) {
  const frame = getAvatarFrame(frameKey);
  const size = className ?? "";

  if (!frame) {
    return <div className={`${PLAIN} ${size}`}>{children}</div>;
  }

  if (frame.variant === "video" && frame.video) {
    if (preview) {
      return (
        <div className={`${styles.videoPreview} ${size}`}>
          <div className={styles.videoPreviewInner}>{children}</div>
          {/* eslint-disable-next-line @next/next/no-img-element -- decorative still of a local asset */}
          <img alt="" aria-hidden="true" className={styles.videoPreviewLayer} src={frame.video.poster} />
        </div>
      );
    }

    // No ring: the burning edge of the video is the frame. The overlay sits after the photo so it paints on top.
    return (
      <div className={`${styles.videoFrame} ${size}`}>
        <div className={styles.videoInner}>{children}</div>
        <AvatarFrameVideoLayer className={styles.videoLayer} video={frame.video} />
      </div>
    );
  }

  const style = frame.color ? ({ "--c1": frame.color } as CSSProperties) : undefined;

  return (
    <div className={`${styles.frame} ${styles[frame.variant]} ${size}`} style={style}>
      {frame.variant === "spectral" ? (
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
