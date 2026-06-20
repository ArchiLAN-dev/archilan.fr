import type { CSSProperties } from "react";
import { getBannerPreset } from "./banner-presets";
import styles from "./profile-banner.module.css";

/**
 * Renders a profile banner from a preset key: an animated gradient base, optional blurred mesh blobs, and
 * an optional texture overlay. Used both full-size (profile header) and compact (editor swatches). Motion
 * is disabled under prefers-reduced-motion (handled in CSS).
 */
export function ProfileBanner({
  presetKey,
  className,
  compact = false,
}: {
  presetKey: string;
  className?: string;
  compact?: boolean;
}) {
  const preset = getBannerPreset(presetKey);
  const [c1, c2, c3] = preset.gradient;
  const rootStyle = { "--c1": c1, "--c2": c2, "--c3": c3 } as CSSProperties;

  return (
    <div
      aria-hidden="true"
      className={`${styles.banner}${compact ? ` ${styles.compact}` : ""}${className ? ` ${className}` : ""}`}
      style={rootStyle}
    >
      <span className={styles.gradient} />
      {preset.blobs?.map((blob, i) => (
        <span
          className={styles.blob}
          key={i}
          style={{ "--blob": blob.color, left: blob.cx, top: blob.cy, width: blob.size } as CSSProperties}
        />
      ))}
      {preset.texture ? <span className={`${styles.texture} ${styles[preset.texture]}`} /> : null}
      <span className={styles.shade} />
    </div>
  );
}
