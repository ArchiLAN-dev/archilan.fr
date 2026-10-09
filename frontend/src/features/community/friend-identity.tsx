import Link from "next/link";

import type { FriendCard } from "./community-friends-api";
import { MemberAvatar } from "./member-avatar";
import { TitledName } from "@/features/community/titled-name";

/** A member's avatar and name, linked to their profile when asked (friends list, suggestions). */
export function FriendIdentity({ card, link = false }: { card: FriendCard; link?: boolean }) {
  const name = card.displayName ?? card.slug;
  const inner = (
    <span className="flex min-w-0 flex-1 items-center gap-3">
      <MemberAvatar
        avatarAnimatedUrl={card.avatarAnimatedUrl}
        avatarUrl={card.avatarUrl}
        frame={card.avatarFrame}
        framing={card.avatarFraming}
        name={name}
        size={36}
      />
      <span className="min-w-0 truncate text-sm font-medium text-foreground">
        <TitledName style={card.nameStyle} variant="card">
          {name}
        </TitledName>
      </span>
    </span>
  );

  return link ? (
    <Link className="flex items-center gap-3 hover:text-accent-text" href={`/joueurs/${card.slug}`}>
      {inner}
    </Link>
  ) : (
    inner
  );
}
