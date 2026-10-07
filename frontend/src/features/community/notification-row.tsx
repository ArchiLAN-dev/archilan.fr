"use client";

import Link from "next/link";
import { useState, type ReactNode } from "react";
import {
  Bell,
  Heart,
  Loader2,
  LockOpen,
  MessageSquare,
  Puzzle,
  ScrollText,
  ShieldAlert,
  Shovel,
  Sparkles,
  Trophy,
  TriangleAlert,
  UserPlus,
} from "lucide-react";

import { getAvatarFrame } from "./avatar-frames";
import { acceptFriendship, declineFriendship } from "./community-friends-api";
import { MemberAvatar } from "./member-avatar";
import { nameColor } from "./name-colors";
import { contentFor, kudosTitle, type NotificationContent, type NotificationIcon, type NotificationTone, type Segment } from "./notification-content";
import type { NotificationItem } from "./notifications-api";
import { ProfileTitleBadge, TitleIconGlyph } from "./profile-title-badge";

const TONE_TEXT: Record<NotificationTone, string> = {
  reward: "text-amber-400",
  pelles: "text-success",
  social: "text-accent-text",
  run: "text-accent-text",
  action: "text-warning",
  moderation: "text-danger",
};

const TONE_TILE: Record<NotificationTone, string> = {
  reward: "bg-amber-400/15 text-amber-400",
  pelles: "bg-success/15 text-success",
  social: "bg-accent-text/15 text-accent-text",
  run: "bg-accent-text/15 text-accent-text",
  action: "bg-warning/15 text-warning",
  moderation: "bg-danger/15 text-danger",
};

const ICONS: Record<NotificationIcon, typeof Bell> = {
  pelles: Shovel,
  quests: ScrollText,
  unlock: LockOpen,
  alert: TriangleAlert,
  shield: ShieldAlert,
  trophy: Trophy,
  puzzle: Puzzle,
  comment: MessageSquare,
  heart: Heart,
  friend: UserPlus,
  bell: Bell,
};

type Props = {
  /** One notification, or the kudos of one day gathered. */
  items: NotificationItem[];
  href: string;
  /** The plain sentence of the first item (`messageFor`), for a type the bell does not draw. */
  fallback: string;
  timeLabel: string;
  /** Opening the row: closes the bell and marks the row read. */
  onSelect: () => void;
  /** Marks the row read without leaving the bell (a friend request answered in place). */
  onRead: () => void;
};

/** Story 30.48: a notification of the bell - its visual, its family, the sentence with its names, its actions. */
export function NotificationRow({ items, href, fallback, timeLabel, onSelect, onRead }: Props) {
  const first = items[0];
  if (first === undefined) return null;
  const content = contentFor(first, fallback);
  const title = first.type === "kudos_received" && items.length > 1 ? kudosTitle(items) : content.title;
  const unread = items.some((item) => !item.read);

  return (
    <div className={`relative flex gap-3 px-4 py-3 transition-colors hover:bg-accent/10 ${unread ? "bg-accent/5" : ""}`}>
      <Visual content={content} items={items} />
      <div className="flex min-w-0 flex-1 flex-col gap-0.5">
        <span className={`text-[11px] font-semibold uppercase tracking-wide ${TONE_TEXT[content.tone]}`}>{content.label}</span>
        {/* The whole row is the link; the actions below sit above it. */}
        <Link className="text-sm leading-snug text-foreground after:absolute after:inset-0" href={href} onClick={onSelect}>
          {content.amount !== null ? <AmountPill amount={content.amount} /> : null}
          <Sentence segments={title} />
        </Link>
        {content.titleBadge ? (
          <ProfileTitleBadge className="self-start" title={{ ...content.titleBadge, access: "reward" }} variant="card" />
        ) : null}
        {content.detail !== null ? <span className="text-[13px] leading-snug text-muted-foreground">{content.detail}</span> : null}
        <Actions content={content} href={href} onRead={onRead} onSelect={onSelect} />
        <span className="text-xs text-muted-foreground">{timeLabel}</span>
      </div>
      {unread ? <span aria-hidden className="mt-1.5 size-2 shrink-0 rounded-full bg-accent-text" /> : null}
    </div>
  );
}

function Sentence({ segments }: { segments: Segment[] }) {
  return segments.map((segment, index) =>
    segment.strong ? (
      <strong className="font-semibold" key={index}>
        {segment.text}
      </strong>
    ) : (
      <span key={index}>{segment.text}</span>
    ),
  );
}

function AmountPill({ amount }: { amount: number }) {
  const credit = amount >= 0;
  return (
    <span className={`mr-1.5 inline-flex h-5 items-center rounded-full px-2 text-xs font-bold ${credit ? "bg-success/15 text-success" : "bg-danger/15 text-danger"}`}>
      {credit ? `+${amount}` : `−${Math.abs(amount)}`}
    </span>
  );
}

function Tile({ tone, children }: { tone: NotificationTone; children: ReactNode }) {
  return <span className={`inline-flex size-10 shrink-0 items-center justify-center rounded-lg ${TONE_TILE[tone]}`}>{children}</span>;
}

function Visual({ content, items }: { content: NotificationContent; items: NotificationItem[] }) {
  const visual = content.visual;
  if (visual.kind === "actors") {
    const actors = [...new Map(items.flatMap((i) => (i.actor ? [[i.actor.slug, i.actor] as const] : []))).values()];
    const [a, b] = actors;
    if (a === undefined) return <Tile tone={content.tone}>{icon("friend")}</Tile>;
    if (b === undefined) {
      return <MemberAvatar avatarUrl={a.avatarUrl} className="shrink-0" frame={a.avatarFrame ?? null} name={a.displayName ?? a.slug} size={40} />;
    }
    return (
      <span className="relative size-10 shrink-0">
        <MemberAvatar avatarUrl={a.avatarUrl} className="absolute left-0 top-0" name={a.displayName ?? a.slug} size={26} />
        <MemberAvatar avatarUrl={b.avatarUrl} className="absolute bottom-0 right-0 ring-2 ring-surface" name={b.displayName ?? b.slug} size={26} />
      </span>
    );
  }
  if (visual.kind === "achievement") {
    return visual.imageUrl !== null ? (
      // eslint-disable-next-line @next/next/no-img-element -- remote presigned image, not a local asset
      <img alt="" className="size-10 shrink-0 rounded-full object-cover ring-2 ring-amber-400" src={visual.imageUrl} />
    ) : (
      <Tile tone={content.tone}>{icon("trophy")}</Tile>
    );
  }
  if (visual.kind === "cosmetic") return <CosmeticVisual content={content} type={visual.cosmeticType} cosmeticKey={visual.key} image={visual.image} />;
  return <Tile tone={content.tone}>{icon(visual.icon)}</Tile>;
}

function CosmeticVisual({ content, type, cosmeticKey, image }: { content: NotificationContent; type: string; cosmeticKey: string; image: string | null }) {
  const picture = image ?? (type === "frame" ? (getAvatarFrame(cosmeticKey)?.video?.poster ?? null) : null);
  if (picture !== null) {
    // eslint-disable-next-line @next/next/no-img-element -- a poster from the media bucket or the frame catalog
    return <img alt="" className="size-10 shrink-0 rounded-lg bg-black object-cover" src={picture} />;
  }
  if (type === "color") {
    const color = nameColor(cosmeticKey);
    if (color !== null) {
      return (
        <Tile tone={content.tone}>
          <span aria-hidden className="size-5 rounded-full" style={{ backgroundColor: color.hex }} />
        </Tile>
      );
    }
  }
  if (type === "title" && content.titleBadge?.icon) {
    return (
      <Tile tone={content.tone}>
        <TitleIconGlyph className="size-5" icon={content.titleBadge.icon} />
      </Tile>
    );
  }
  return <Tile tone={content.tone}>{<Sparkles aria-hidden className="size-5" />}</Tile>;
}

function icon(name: NotificationIcon) {
  const Icon = ICONS[name];
  return <Icon aria-hidden className="size-5" />;
}

/** The answer to a friend request, or the shortcut to wear a cosmetic, above the row's link. */
function Actions({ content, href, onSelect, onRead }: { content: NotificationContent; href: string; onSelect: () => void; onRead: () => void }) {
  if (content.friendRequestId !== null) return <FriendRequestActions friendshipId={content.friendRequestId} onAnswered={onRead} />;
  if (content.wearable) {
    return (
      <Link
        className="relative z-10 mt-1 inline-flex min-h-8 items-center self-start rounded-lg bg-accent px-3 text-xs font-semibold text-white hover:bg-accent-hover"
        href={href}
        onClick={onSelect}
      >
        Porter
      </Link>
    );
  }
  return null;
}

function FriendRequestActions({ friendshipId, onAnswered }: { friendshipId: string; onAnswered: () => void }) {
  const [state, setState] = useState<"idle" | "pending" | "accepted" | "declined" | "error">("idle");

  async function answer(accept: boolean): Promise<void> {
    setState("pending");
    const ok = await (accept ? acceptFriendship(friendshipId) : declineFriendship(friendshipId));
    setState(ok ? (accept ? "accepted" : "declined") : "error");
    if (ok) onAnswered();
  }

  if (state === "accepted") return <span className="text-xs font-semibold text-success">Demande acceptée</span>;
  if (state === "declined") return <span className="text-xs text-muted-foreground">Demande refusée</span>;

  return (
    <span className="relative z-10 mt-1 flex flex-wrap items-center gap-2">
      <button
        className="inline-flex min-h-8 items-center gap-1.5 rounded-lg bg-accent px-3 text-xs font-semibold text-white hover:bg-accent-hover disabled:opacity-50"
        disabled={state === "pending"}
        onClick={() => void answer(true)}
        type="button"
      >
        {state === "pending" ? <Loader2 aria-hidden className="size-3.5 animate-spin" /> : null}
        Accepter
      </button>
      <button
        className="inline-flex min-h-8 items-center rounded-lg border border-border px-3 text-xs font-semibold text-muted-foreground hover:text-foreground disabled:opacity-50"
        disabled={state === "pending"}
        onClick={() => void answer(false)}
        type="button"
      >
        Refuser
      </button>
      {state === "error" ? <span className="text-xs text-danger">Impossible de répondre.</span> : null}
    </span>
  );
}
