"use client";

import { useState } from "react";

import { Dialog, DialogBody } from "@/components/ui/dialog";
import type { FramePreviewBanner } from "@/features/community/frame-preview";
import { CENTRED_FRAMING, type ImageFraming } from "@/features/community/image-framing";
import { MemberAvatar } from "@/features/community/member-avatar";
import type { NameColorStyle } from "@/features/community/name-colors";
import { ProfileBanner } from "@/features/community/profile-banner";
import { ProfileTitleBadge } from "@/features/community/profile-title-badge";
import type { TitleBadge } from "@/features/community/profile-title-catalog";
import { TitledName, type NameStyle, type RarityStyle } from "@/features/community/titled-name";
import type { ShopItem } from "./shop-api";
import { COSMETIC_TYPE_LABELS, useTitleBadge } from "./shop-cosmetics";

/** The member trying things on: their photo, frame, banner, name style and title, as on their profile. */
export type ShopShopper = {
  avatarUrl: string | null;
  name: string;
  framing: ImageFraming | null;
  frame: string | null;
  banner: FramePreviewBanner;
  /** Story 41.32: the name as their profile draws it - a rarity style or a colour - null for a plain one. */
  nameStyle?: NameStyle | null;
  /** Story 41.32: a rarity style that wins over any colour on their profile (« Pseudo à titre »), null for none. */
  rarityStyle?: RarityStyle | null;
  /** Story 41.32: the title they wear (its key), null for none. */
  title?: string | null;
};

export const VISITOR: ShopShopper = {
  avatarUrl: null,
  name: "Toi",
  framing: null,
  frame: null,
  banner: { presetKey: "default", imageUrl: null, framing: CENTRED_FRAMING, overlay: 50 },
};

/** What the profile header shows. */
export type ProfileLook = {
  avatarUrl: string | null;
  name: string;
  framing: ImageFraming | null;
  frame: string | null;
  banner: FramePreviewBanner;
  nameStyle: NameStyle | null;
  title: TitleBadge | null;
};

/** The member's header as it is today. */
export function currentLook(shopper: ShopShopper, titleBadge: (key: string) => TitleBadge | null): ProfileLook {
  return {
    avatarUrl: shopper.avatarUrl,
    name: shopper.name,
    framing: shopper.framing,
    frame: shopper.frame,
    banner: shopper.banner,
    nameStyle: shopper.nameStyle ?? null,
    title: shopper.title ? titleBadge(shopper.title) : null,
  };
}

/**
 * The same header with the item in place of what the member wears. A banner tried on shows alone (the member's own
 * image would hide it); a colour shows even where their rarity style would win on the real profile.
 */
export function lookWith(look: ProfileLook, item: Pick<ShopItem, "type" | "cosmeticKey">, title: TitleBadge | null): ProfileLook {
  switch (item.type) {
    case "frame":
      return { ...look, frame: item.cosmeticKey };
    case "banner":
      return { ...look, banner: { presetKey: item.cosmeticKey, imageUrl: null, framing: CENTRED_FRAMING, overlay: 50 } };
    case "title":
      return { ...look, title: title ?? { label: item.cosmeticKey, rarity: "common", icon: null, access: "shop" } };
    case "color":
      return { ...look, nameStyle: `color-${item.cosmeticKey}` as NameColorStyle };
  }
}

/** The top of a profile: banner, photo and frame straddling its bottom, then the name and the title. */
export function ProfileHeaderPreview({ look }: { look: ProfileLook }) {
  return (
    // No z-index anywhere in here: a stacking context would cut a video frame's blend off the banner behind it.
    <div className="relative overflow-hidden rounded-xl border border-border bg-surface px-6 pb-6 pt-16 sm:pt-20">
      {/* The banner sets its own `relative`: an outer box positions it. */}
      <div className="absolute inset-x-0 top-0 h-28 sm:h-32">
        <ProfileBanner className="size-full" framing={look.banner.framing} imageUrl={look.banner.imageUrl} overlay={look.banner.overlay} presetKey={look.banner.presetKey} />
      </div>
      <div className="relative flex flex-col items-center gap-3 text-center">
        <MemberAvatar animate="always" avatarUrl={look.avatarUrl} frame={look.frame} framing={look.framing} name={look.name} size={112} sizeClassName="size-24 sm:size-28" />
        {/* Room for a rarity title, which hangs above the name. */}
        <span className="pt-4 font-heading text-2xl font-bold text-foreground">
          <TitledName style={look.nameStyle} variant="profile">
            {look.name}
          </TitledName>
        </span>
        {look.title ? <ProfileTitleBadge title={look.title} /> : null}
      </div>
    </div>
  );
}

const RARITY_NAMES: Record<RarityStyle, string> = { legendary: "légendaire", epic: "épique" };

/** Story 41.32: the item on the member's whole profile header, before and after. Nothing is saved. */
export function TryOnDialog({ item, label, shopper, onClose }: { item: ShopItem; label: string; shopper: ShopShopper; onClose: () => void }) {
  const titleBadge = useTitleBadge();
  const [after, setAfter] = useState(true);
  const before = currentLook(shopper, titleBadge);
  const look = after ? lookWith(before, item, item.type === "title" ? titleBadge(item.cosmeticKey) : null) : before;

  return (
    <Dialog
      description={`${COSMETIC_TYPE_LABELS[item.type]} sur ton profil, rien n'est enregistré.`}
      onOpenChange={(open) => {
        if (!open) onClose();
      }}
      open
      size="wide"
      title={`Essayer « ${label} »`}
    >
      <DialogBody>
        <TryOnBody after={after} item={item} look={look} onToggle={setAfter} shopper={shopper} />
      </DialogBody>
    </Dialog>
  );
}

export function TryOnBody({
  item,
  look,
  shopper,
  after,
  onToggle,
}: {
  item: Pick<ShopItem, "type">;
  look: ProfileLook;
  shopper: ShopShopper;
  after: boolean;
  onToggle: (after: boolean) => void;
}) {
  const rarity = item.type === "color" ? (shopper.rarityStyle ?? null) : null;
  return (
    <div className="grid gap-3">
      <div aria-label="Comparer" className="inline-flex w-fit rounded-lg border border-border bg-surface p-0.5 text-sm" role="group">
        {(
          [
            [false, "Avant"],
            [true, "Avec l'objet"],
          ] as const
        ).map(([value, text]) => (
          <button
            aria-pressed={after === value}
            className={`min-h-9 rounded-md px-3 font-medium ${after === value ? "bg-accent text-white" : "text-muted-foreground hover:text-foreground"}`}
            key={text}
            onClick={() => onToggle(value)}
            type="button"
          >
            {text}
          </button>
        ))}
      </div>
      <ProfileHeaderPreview look={look} />
      {rarity !== null && after ? (
        <p className="text-xs text-muted-foreground">
          Sur ton profil, ton pseudo {RARITY_NAMES[rarity]} passe avant les couleurs : décoche « Pseudo à titre » dans ton profil pour porter
          celle-ci.
        </p>
      ) : null}
    </div>
  );
}
