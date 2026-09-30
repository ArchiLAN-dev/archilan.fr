import type { CSSProperties } from "react";
import { getBannerPreset, type BannerPresetConfig } from "./banner-presets";
import { framingStyle, type ImageFraming } from "./image-framing";
import styles from "./profile-banner.module.css";

/** Default strength of the preset laid over a banner image (story 30.41), mirroring `BannerOverlay::DEFAULT`. */
export const DEFAULT_BANNER_OVERLAY = 50;

/**
 * Renders a profile banner from a preset key: an animated gradient base, optional blurred mesh blobs, and
 * an optional texture overlay. Used both full-size (profile header) and compact (editor swatches). Motion
 * is disabled under prefers-reduced-motion (handled in CSS).
 *
 * Story 30.40: an uploaded image fills the banner, under the same shade so the name stays readable; a moving GIF
 * gives way to its first frame when the visitor asks for less motion. Story 30.41: the preset lies over that
 * image at `overlay` percent opacity, so both animations add up (0 = the image alone).
 */
export function ProfileBanner({
  presetKey,
  imageUrl = null,
  imageStillUrl = null,
  overlay = DEFAULT_BANNER_OVERLAY,
  framing = null,
  className,
  compact = false,
}: {
  presetKey: string;
  imageUrl?: string | null;
  imageStillUrl?: string | null;
  overlay?: number;
  /** Story 30.43: the part of the image shown. */
  framing?: ImageFraming | null;
  className?: string;
  compact?: boolean;
}) {
  const preset = getBannerPreset(presetKey);

  if (imageUrl !== null) {
    const opacity = Math.min(100, Math.max(0, overlay)) / 100;
    return (
      // relative + overflow-hidden on the element itself: the image must stay inside the banner, module or not.
      <div aria-hidden="true" className={`${styles.banner} relative overflow-hidden${className ? ` ${className}` : ""}`}>
        <picture>
          {imageStillUrl !== null ? <source media="(prefers-reduced-motion: reduce)" srcSet={imageStillUrl} /> : null}
          {/* A plain img, like the avatars: a presigned storage URL, possibly an animated GIF. */}
          <img alt="" className="absolute inset-0 size-full object-cover" src={imageUrl} style={framingStyle(framing)} />
        </picture>
        {opacity > 0 ? (
          <div className="absolute inset-0" style={{ ...presetVariables(preset), opacity }}>
            <PresetLayers preset={preset} />
          </div>
        ) : null}
        <span className={styles.shade} />
      </div>
    );
  }

  return (
    <div
      aria-hidden="true"
      className={`${styles.banner}${compact ? ` ${styles.compact}` : ""}${className ? ` ${className}` : ""}`}
      style={presetVariables(preset)}
    >
      <PresetLayers preset={preset} />
      <span className={styles.shade} />
    </div>
  );
}

function presetVariables(preset: BannerPresetConfig): CSSProperties {
  const [c1, c2, c3] = preset.gradient;
  return { "--c1": c1, "--c2": c2, "--c3": c3 } as CSSProperties;
}

function PresetLayers({ preset }: { preset: BannerPresetConfig }) {
  return (
    <>
      <span className={styles.gradient} />
      {preset.blobs?.map((blob, i) => (
        <span
          className={styles.blob}
          key={i}
          style={{ "--blob": blob.color, left: blob.cx, top: blob.cy, width: blob.size } as CSSProperties}
        />
      ))}
      {preset.texture ? <span className={`${styles.texture} ${styles[preset.texture]}`} /> : null}
    </>
  );
}
