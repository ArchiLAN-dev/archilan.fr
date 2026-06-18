import type { CSSProperties, ReactNode } from "react";
import { getAvatarFrame } from "./avatar-frames";
import styles from "./avatar-frame.module.css";

const PLAIN = "overflow-hidden rounded-2xl border-4 border-surface ring-1 ring-border bg-surface";

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
      <div className={styles.inner}>{children}</div>
    </div>
  );
}
