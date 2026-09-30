import type { CSSProperties } from "react";
import { getBannerPreset } from "./banner-presets";
import styles from "./profile-banner.module.css";

/**
 * Renders a profile banner from a preset key: an animated gradient base, optional blurred mesh blobs, and
 * an optional texture overlay. Used both full-size (profile header) and compact (editor swatches). Motion
 * is disabled under prefers-reduced-motion (handled in CSS).
 *
 * Story 30.40: an uploaded image covers the banner instead, under the same shade so the name stays readable;
 * a moving GIF gives way to its first frame when the visitor asks for less motion.
 */
export function ProfileBanner({
  presetKey,
  imageUrl = null,
  imageStillUrl = null,
  className,
  compact = false,
}: {
  presetKey: string;
  imageUrl?: string | null;
  imageStillUrl?: string | null;
  className?: string;
  compact?: boolean;
}) {
  if (imageUrl !== null) {
    return (
      // relative + overflow-hidden on the element itself: the image must stay inside the banner, module or not.
      <div aria-hidden="true" className={`${styles.banner} relative overflow-hidden${className ? ` ${className}` : ""}`}>
        <picture>
          {imageStillUrl !== null ? <source media="(prefers-reduced-motion: reduce)" srcSet={imageStillUrl} /> : null}
          {/* A plain img, like the avatars: a presigned storage URL, possibly an animated GIF. */}
          <img alt="" className="absolute inset-0 size-full object-cover" src={imageUrl} />
        </picture>
        <span className={styles.shade} />
      </div>
    );
  }

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
