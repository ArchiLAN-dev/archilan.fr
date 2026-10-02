"use client";

import { useState, type CSSProperties } from "react";
import { Lock } from "lucide-react";

import { buttonVariants } from "@/components/ui/button";
import { Dialog, DialogBody, DialogFooter } from "@/components/ui/dialog";
import { AvatarFrame } from "./avatar-frame";
import { AVATAR_FRAMES, type AvatarFrameCategory } from "./avatar-frames";
import { FramePreview, type FramePreviewBanner } from "./frame-preview";
import { AvatarContent } from "./member-avatar";
import type { ImageFraming } from "./image-framing";

export const FRAME_CATEGORIES: readonly AvatarFrameCategory[] = ["Couleurs", "Néon", "Effets", "Légendaires"];
export const LEGENDARY_CATEGORY: AvatarFrameCategory = "Légendaires";
// The cards draw their 48 px avatar with the frame scaled to it (story 30.47).
const CARD_SCALE = { "--s": 48 / 112 } as CSSProperties;

type Avatar = { avatarUrl: string | null; name: string; framing: ImageFraming | null };

/**
 * Story 30.46: the avatar frame picker, in its own window as Discord's decoration picker does. Every frame is the
 * same card, grouped under plain headings, the legendary ones told apart by a rarity border rather than a size;
 * the big preview beside them shows the frame on the member's photo and banner. "Appliquer" only puts the frame
 * in the profile draft: nothing is saved until "Enregistrer".
 */
export function FramePickerDialog({
  open,
  onOpenChange,
  current,
  saved,
  legendaryAllowed,
  avatar,
  banner,
  onApply,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** The frame in the draft: the picker opens on it. */
  current: string | null;
  /** The frame as last saved: a dot marks it. */
  saved: string | null;
  legendaryAllowed: boolean;
  avatar: Avatar;
  banner: FramePreviewBanner;
  onApply: (frame: string | null) => void;
}) {
  return (
    <Dialog onOpenChange={onOpenChange} open={open} size="wide" title="Cadre d'avatar">
      {/* The content unmounts when the dialog closes, so each opening starts again from the draft. */}
      <FramePicker
        avatar={avatar}
        banner={banner}
        current={current}
        legendaryAllowed={legendaryAllowed}
        onApply={(frame) => {
          onApply(frame);
          onOpenChange(false);
        }}
        onCancel={() => onOpenChange(false)}
        saved={saved}
      />
    </Dialog>
  );
}

/** The picker's content (exported for tests: the dialog itself only renders in a browser). */
export function FramePicker({
  current,
  saved,
  legendaryAllowed,
  avatar,
  banner,
  onApply,
  onCancel,
}: {
  current: string | null;
  saved: string | null;
  legendaryAllowed: boolean;
  avatar: Avatar;
  banner: FramePreviewBanner;
  onApply: (frame: string | null) => void;
  onCancel: () => void;
}) {
  const [pick, setPick] = useState(current);

  return (
    <>
      <DialogBody className="md:grid-cols-[minmax(0,1fr)_auto] md:items-start md:gap-6">
        <div className="md:sticky md:top-0 md:order-2">
          <FramePreview avatarUrl={avatar.avatarUrl} banner={banner} frame={pick} framing={avatar.framing} name={avatar.name} />
        </div>
        <div className="grid gap-5">
          {FRAME_CATEGORIES.map((category) => {
            const legendary = category === LEGENDARY_CATEGORY;
            const locked = legendary && !legendaryAllowed;
            return (
              <section className="grid gap-2" key={category}>
                <h3 className="flex items-center gap-1.5 text-sm font-semibold text-foreground">
                  {category}
                  {locked ? (
                    <span className="inline-flex items-center gap-1 text-xs font-normal text-muted-foreground">
                      <Lock aria-hidden className="size-3" /> réservés aux admins pour l&apos;instant
                    </span>
                  ) : null}
                </h3>
                <div className="grid grid-cols-[repeat(auto-fill,minmax(5.5rem,1fr))] gap-2">
                  {category === FRAME_CATEGORIES[0] ? (
                    <FrameCard avatar={avatar} frameKey={null} label="Aucun" onPick={setPick} saved={null === saved} selected={null === pick} />
                  ) : null}
                  {AVATAR_FRAMES.filter((f) => f.category === category).map((f) => (
                    <FrameCard
                      avatar={avatar}
                      frameKey={f.key}
                      key={f.key}
                      label={f.label}
                      legendary={legendary}
                      locked={locked}
                      onPick={setPick}
                      saved={saved === f.key}
                      selected={pick === f.key}
                    />
                  ))}
                </div>
              </section>
            );
          })}
        </div>
      </DialogBody>
      <DialogFooter>
        <button className={buttonVariants({ variant: "secondary" })} onClick={onCancel} type="button">
          Annuler
        </button>
        <button className={buttonVariants({ variant: "primary" })} onClick={() => onApply(pick)} type="button">
          Appliquer
        </button>
      </DialogFooter>
    </>
  );
}

/**
 * One frame of the picker, on the member's own photo. Every card has the same size; a legendary one wears a gold
 * rarity border and plays its video while hovered or focused. A locked one (not admin) can't be picked. A dot marks
 * the saved frame.
 */
function FrameCard({
  frameKey,
  label,
  selected,
  saved,
  legendary = false,
  locked = false,
  onPick,
  avatar,
}: {
  frameKey: string | null;
  label: string;
  selected: boolean;
  saved: boolean;
  legendary?: boolean;
  locked?: boolean;
  onPick: (key: string | null) => void;
  avatar: Avatar;
}) {
  const [hovered, setHovered] = useState(false);

  const border = selected
    ? "border-accent bg-accent/10 ring-2 ring-accent/40"
    : legendary
      ? "border-amber-400/50 shadow-[0_0_14px_-4px] shadow-amber-400/60 hover:border-amber-300"
      : "border-border hover:border-accent/60";

  return (
    <button
      aria-label={locked ? `${label} (réservé aux admins)` : label}
      aria-pressed={selected}
      className={`relative grid justify-items-center gap-1.5 rounded-lg border p-2 transition-colors disabled:cursor-not-allowed ${border}`}
      disabled={locked}
      onBlur={() => setHovered(false)}
      onClick={() => onPick(frameKey)}
      onFocus={() => setHovered(true)}
      onMouseEnter={() => setHovered(true)}
      onMouseLeave={() => setHovered(false)}
      title={locked ? "Réservé aux admins pour l'instant" : label}
      type="button"
    >
      <AvatarFrame animated={hovered && !locked} className="size-12" frameKey={frameKey} preview style={CARD_SCALE}>
        <AvatarContent avatarUrl={avatar.avatarUrl} framing={avatar.framing} name={avatar.name} size={48} />
      </AvatarFrame>
      {locked ? (
        <Lock aria-hidden className="absolute right-1.5 top-1.5 size-3.5 text-muted-foreground" />
      ) : saved ? (
        <span aria-hidden className="absolute right-2 top-2 size-1.5 rounded-full bg-accent" />
      ) : null}
      <span className={`line-clamp-2 min-h-[2lh] w-full text-center text-[11px] leading-tight ${locked ? "text-muted-foreground/60" : "text-muted-foreground"}`}>
        {label}
      </span>
    </button>
  );
}
