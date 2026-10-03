import type { CSSProperties } from "react";
import { getBannerPreset, type BannerPresetConfig } from "./banner-presets";
import { CatalogProfileBanner } from "./catalog-profile-banner";
import { bannerVideo, isBannerPresetKey, type ProfileBannerMedia } from "./profile-banner-catalog";
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
 *
 * Story 41.11: a key the code does not know is a banner of the admin catalog, resolved on the client; its still
 * image, or its looped video, takes the place of the preset's gradient (the still one under reduced motion).
 */
export function ProfileBanner({
  presetKey,
  imageUrl = null,
  imageStillUrl = null,
  overlay = DEFAULT_BANNER_OVERLAY,
  framing = null,
  className,
  compact = false,
  media,
}: {
  presetKey: string;
  imageUrl?: string | null;
  imageStillUrl?: string | null;
  overlay?: number;
  /** Story 30.43: the part of the image shown. */
  framing?: ImageFraming | null;
  className?: string;
  compact?: boolean;
  /** Story 41.11: the files of a catalog banner, once resolved (null: none, the preset draws). */
  media?: ProfileBannerMedia | null;
}) {
  if (media === undefined && !isBannerPresetKey(presetKey)) {
    return (
      <CatalogProfileBanner
        className={className}
        compact={compact}
        framing={framing}
        imageStillUrl={imageStillUrl}
        imageUrl={imageUrl}
        overlay={overlay}
        presetKey={presetKey}
      />
    );
  }

  const preset = getBannerPreset(presetKey);
  const layers = media ? <MediaLayers compact={compact} media={media} /> : <PresetLayers preset={preset} />;

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
            {layers}
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
      {layers}
      <span className={styles.shade} />
    </div>
  );
}

/** Story 41.11: a catalog banner - its still image, and over it its video, hidden under reduced motion. */
function MediaLayers({ media, compact }: { media: ProfileBannerMedia; compact: boolean }) {
  const video = compact ? null : bannerVideo(media);
  return (
    <>
      {/* eslint-disable-next-line @next/next/no-img-element -- a plain img, like the avatars: a file of the public media bucket */}
      <img alt="" className="absolute inset-0 size-full object-cover" src={media.image} />
      {video !== null ? (
        <video autoPlay className={`absolute inset-0 size-full object-cover ${styles.mediaVideo}`} loop muted playsInline poster={media.image}>
          <source src={video.webm} type="video/webm" />
          <source src={video.mp4} type="video/mp4" />
        </video>
      ) : null}
    </>
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
