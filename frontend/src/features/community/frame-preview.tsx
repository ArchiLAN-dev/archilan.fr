import type { ImageFraming } from "./image-framing";
import { MemberAvatar } from "./member-avatar";
import { ProfileBanner } from "./profile-banner";

export type FramePreviewBanner = {
  presetKey: string;
  imageUrl: string | null;
  framing: ImageFraming;
  overlay: number;
};

/**
 * The frame being tried on, on the member's own photo at profile size, straddling the bottom of their own banner as
 * on the profile page, with room around it for the overflowing effects (story 30.46). Screen-blended effects read
 * differently on a light banner and on the dark card: this shows both. Picking a frame only changes the draft:
 * nothing is saved until "Enregistrer".
 */
export function FramePreview({
  avatarUrl,
  name,
  framing,
  frame,
  banner,
}: {
  avatarUrl: string | null;
  name: string;
  framing: ImageFraming | null;
  frame: string | null;
  banner: FramePreviewBanner;
}) {
  return (
    // No z-index anywhere in here: a stacking context would cut the frame's blend off the banner behind it.
    <div className="relative flex justify-center overflow-hidden rounded-xl border border-border bg-surface px-12 pb-10 pt-14">
      {/* The banner sets its own `relative`: an outer box positions it. */}
      <div className="absolute inset-x-0 top-0 h-3/5">
        <ProfileBanner className="size-full" framing={banner.framing} imageUrl={banner.imageUrl} overlay={banner.overlay} presetKey={banner.presetKey} />
      </div>
      <div className="relative">
        <MemberAvatar animate="always" avatarUrl={avatarUrl} frame={frame} framing={framing} name={name} size={112} sizeClassName="size-24 sm:size-28" />
      </div>
    </div>
  );
}
